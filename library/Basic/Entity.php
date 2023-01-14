<?php

#[AllowDynamicProperties]
class Basic_Entity
{
	protected $id;
	private $_dbData;

	private static $_cache;
	protected static $_relations = [];
	protected static $_numerical = [];
	protected static $_serialized = [];
	protected static $_order = ['id' => true];

	private function __construct()
	{
		$this->_dbData = clone $this;

		// Let __get handle relations lazily
		foreach (static::$_relations as $property => $class)
			unset($this->$property);

		foreach (static::$_numerical as $property)
			if (property_exists($this, $property) && null !== $this->$property)
				$this->$property = 1*$this->$property;

		foreach (static::$_serialized as $property)
			if (property_exists($this, $property) && null !== $this->$property)
				$this->$property = unserialize($this->$property);

		// Checks might need a property, so do this after the actual loading
		$this->_checkPermissions('load');

		// Don't cache freshly ::created entities
		if (isset($this->id))
			self::$_cache[ static::class ][ $this->id ] = $this;
	}

	/**
	 * Retrieve an Entity from the db; possible caching it when fetched earlier
	 *
	 * @param mixed $id The id of the Entity to retrieve
	 * @return Basic_Entity
	 */
	public static function get($id): self
	{
		if (!is_scalar($id))
			throw new Basic_Entity_InvalidIdException('Invalid type `%s` for `id`', [gettype($id)]);

		if (!isset(self::$_cache[ static::class ][ $id ]))
		{
			$result = Basic::$database->q("SELECT * FROM ". Basic_Database::escapeTable(static::getTable()) ." WHERE id = ?", [$id]);
			$result->setFetchMode(PDO::FETCH_CLASS, static::class);

			// Allow caching negatives too. Note fetch() calls __construct() which stores in cache
			if (!$result->fetch())
				self::$_cache[ static::class ][ $id ] = false;
		}

		if (false == self::$_cache[ static::class ][ $id ])
			throw new Basic_Entity_NotFoundException('Did not find `%s` with id `%s`', [static::class, $id]);

		return self::$_cache[ static::class ][ $id ];
	}

	/**
	 * Retrieve a stub - an Entity with data provided by the caller
	 * Intended to emulate what PDO->fetch(class) does, set props before __construct()
	 *
	 * @param array $data Associative list of properties to set on the Entity
	 * @return Basic_Entity
	 */
	public static function getStub(array $data = []): self
	{
		$entity = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

		foreach ($data as $k => $v)
			$entity->$k = $v;

		$entity->__construct();

		return $entity;
	}

	/**
	 * Create and save a new Entity using the provided data
	 *
	 * @param array $data Associative list of properties to store
	 * @param bool $reload Reload the Entity from the database before returning
	 * @return Basic_Entity
	 */
	public static function create(array $data = [], bool $reload = true): self
	{
		$entity = new static;
		$entity->_dbData = new StdClass;
		$entity->save($data);

		if ($reload)
			return static::get($entity->id);
		else
			return $entity;
	}

	protected function _isNew(): bool
	{
		return $this->_dbData instanceof StdClass;
	}

	/**
	 * Magic getter, not normally called for actual properties as they are actually defined.
	 * Returns Entities for relations, otherwise passes control to magic _getProperty method
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function __get($key)
	{
		if ('id' == $key)
			return $this->id;

		if (false !== strpos($key, ':id', strlen($key)-3))
		{
			$key = substr($key, 0, -3);

			if (array_key_exists($key, static::$_relations) && isset($this->_dbData->$key))
				return $this->_dbData->$key;
		}

		if (array_key_exists($key, static::$_relations) && isset($this->_dbData->$key))
		{
			$class = static::$_relations[$key];
			$id = $this->_dbData->$key;

			if (isset(self::$_cache[ $class ][ $id ]))
				return $this->$key = self::$_cache[ $class ][ $id ];
			else
				return $this->$key = $class::get($id);
		}

		if (method_exists($this, '_get'. ucfirst($key)))
			return call_user_func([$this, '_get'. ucfirst($key)]);
	}

	public function __isset($key)
	{
		return (null !== $this->__get($key));
	}

	/**
	 * Save this Entity to database. If additional data is provided, it is applied to the Entity.
	 * Attempts to generate a minimal query with only updated data
	 *
	 * @param array $data - Associative list of additional data to save
	 * @return bool Indication if any data was updated. Calling save() twice should return true, false
	 */
	public function save(array $data = []): bool
	{
		if (isset($this->id, $data['id']) && $data['id'] != $this->id)
			throw new Basic_Entity_CannotUpdateIdException('You cannot change the `id` of an object');

		// Apply $data to $this
		foreach ($data as $property => $value)
		{
			if (isset($value, static::$_relations[$property]) && !is_object($value))
			{
				$class = static::$_relations[$property];
				$value = $class::get($value);
			}

			$this->$property = $value;
		}

		$this->_checkPermissions('save');

		// Now determine what properties have changed
		$data = [];
		foreach ($this->_getProperties() as $property)
		{
			$value = $this->$property;

			if (isset($value))
			{
				if ($value === '')
					$value = null;
				elseif (isset(static::$_relations[$property]))
					$value = $value->id;
				elseif (in_array($property, static::$_serialized))
					$value = serialize($value);
				elseif (!is_scalar($value))
					throw new Basic_Entity_InvalidDataException('Value for `%s` contains invalid data `%s`', [$property, gettype($value)]);
			}

			if (isset($this->_dbData->$property) && ($value === $this->_dbData->$property || in_array($property, static::$_numerical) && $value == $this->_dbData->$property))
				continue;

			$data[ $property ] = $value;
		}

		if (empty($data))
			return false;

		if (isset($this->id))
		{
			$fields = implode(' = ?, ', array_map([Basic_Database::class, 'escapeColumn'], array_keys($data)));
			Basic::$database->q("UPDATE ". Basic_Database::escapeTable(static::getTable()) ." SET ". $fields ." = ? WHERE id = ?", array_merge(array_values($data), [$this->id]));

			$this->removeCached();
		}
		else
		{
			$columns = implode(', ', array_map([Basic_Database::class, 'escapeColumn'], array_keys($data)));
			$values = implode(', :', array_keys($data));

			$query = Basic::$database->q("INSERT INTO ". Basic_Database::escapeTable(static::getTable()) ." (". $columns .") VALUES (:". $values .")", $data);

			if (1 != $query->rowCount())
				throw new Basic_Entity_StorageException('New `%s` could not be created', [get_class($this)]);

			try
			{
				$this->id = Basic::$database->lastInsertId(static::getTable(). '_id_seq');
			} catch (PDOException $e) {
				// ignore
			}
		}

		return true;
	}

	protected function _getProperties(): array
	{
			#FIXME doesn't return object_vars that have been unset
		return array_diff(array_keys(get_object_vars($this)), ['id', '_dbData']);
	}

	/**
	 * Find a set of Entities based on specified filters. Will return specific Set-class when defined
	 *
	 * @param string|null $filter Sql filter to apply
	 * @param array $parameters Indexed list of parameters for the filter
	 * @param array $order Associative array of [column-name => bool] to order the Set by, where the TRUE value means ascending
	 * @return Basic_EntitySet
	 */
	public static function find(string $filter = null, array $parameters = [], array $order = null): Basic_EntitySet
	{
		/* @var Basic_EntitySet $set */
		$setClass = static::class .'Set';
		if (!class_exists($setClass))
			eval("class $setClass extends Basic_EntitySet {}");
		$set = new $setClass(static::class);

		return $set
			->getSubset($filter, $parameters)
			->setOrder($order ?? static::$_order);
	}

	public function delete(): void
	{
		$this->_checkPermissions('delete');
		$this->removeCached();

		Basic::$database->q("DELETE FROM ". Basic_Database::escapeTable(static::getTable()) ." WHERE id = ?", [$this->id]);
	}

	public function removeCached(): void
	{
		unset(self::$_cache[ get_class($this) ][ $this->id ]);
	}

	public static function getTable(): string
	{
		return substr(strrchr(static::class, '_'), 1);
	}

	/**
	 * Overloadable, checks permissions; should throw Exception when not permitted
	 *
	 * @param string $action Either load / save or delete
	 */
	protected function _checkPermissions(string $action): void
	{
		return;
	}

	/**
	 * Use properties of this Entity as default values for current Userinput configuration
	 */
	public function setUserinputDefault(): void
	{
		foreach ($this as $key => $value)
		{
			if (!isset(Basic::$userinput->$key))
			{
				Basic::$log->write('SetUserinputDefault failed, property `'. $key .'` on `'. get_class($this). '` is not defined in Basic::$userinput');
				continue;
			}

			$value = isset(static::$_relations[$key]) ? $value->id : $value;

			try
			{
				$this->_setUserinputDefault($key, $value);
			}
			catch (Basic_UserinputValue_InvalidDefaultException $e)
			{
				if (!Basic::$config->PRODUCTION_MODE)
					throw $e;

				Basic::$log->write('InvalidDefaultException for `'. $key .'` on `'. get_class($this). '`, value = '. var_export($value, true). ', caused by '. get_class($e->getPrevious()));
				// ignore, user cannot fix this
			}
		}
	}

	/**
	 * Overloadable, set specified key and value as Userinput.default. Allows processing
	 *
	 * @param string $key Name of the property and UserinputValue to set
	 * @param mixed $value Value to set
	 */
	protected function _setUserinputDefault(string $key, $value): void
	{
		Basic::$userinput->$key->default = $value;
	}

	/**
	 * Find Entities based on their relation to this Entity
	 *
	 * @param Basic_Entity $entityType
	 * @param string $property Optional key entityTypes having multiple relations to same Entity
	 * @return Basic_EntitySet
	 */
	public function getRelated(string $entityType, $property = null): Basic_EntitySet
	{
		if (!isset($property))
		{
			$keys = array_keys($entityType::$_relations, get_class($this), true);

			if (1 != count($keys))
				throw new Basic_Entity_NoSingleRelationFoundException('No single relation to `%s` (found `%d`)', [$entityType, count($keys)]);

			$property = $keys[0];
		} elseif (!isset($entityType::$_relations[$property]))
			throw new Basic_Entity_NoRelationFoundException('No relation found to `%s` called `%s`', [$entityType, $property]);

		return $entityType::find($property ." = ?", [$this->id]);
	}
}