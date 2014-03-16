<?php
class Basic_Entity
{
	private static $_cache;

	protected $id = null;
	private $_dbData;
	protected static $_relations = array();
	protected static $_numerical = array();
	protected static $_serialized = array();
	protected static $_order = array('id' => true);

	public function __construct()
	{
/*		if (is_scalar($id))
		{
			$this->id = $id;

			$result = Basic::$database->query("SELECT * FROM `". static::getTable() ."` WHERE `id` = ?", [$id]);
			foreach ($result->fetch() as $property => $value)
				$this->$property = $value;
		} elseif (isset($id))
			throw new Basic_Entity_InvalidIdException('Invalid type `%s` for `id`', array(gettype($id)));
*/
		if (!empty(func_get_args()))
			throw new Basic_Exception('Erreur');
		if (!isset($this->id))
			return;

		$this->_dbData = clone $this;

		foreach (static::$_relations as $property => $class)
			if (isset($this->$property))
			{
				if (isset(self::$_cache[ $class ][ $this->$property ]))
					$this->$property = self::$_cache[ $class ][ $this->$property ];
				else
					$this->$property = $class::get($this->$property);
			}

		foreach (static::$_numerical as $property)
			if (isset($this->$property))
				$this->$property = intval($this->$property);

		foreach (static::$_serialized as $property)
			if (isset($this->$property))
				$this->$property = unserialize($this->$property);

		// Checks might need a property, so do this after the actual loading
		$this->_checkPermissions('load');

	}

	public static function get($id)
	{
		$class = get_called_class();

		if (!is_scalar($id))
			throw new Basic_Entity_InvalidIdException('Invalid type `%s` for `id`', array(gettype($id)));

		if (!isset(self::$_cache[ $class ][ $id ]))
		{
			$result = Basic::$database->query("SELECT * FROM `". static::getTable() ."` WHERE `id` = ?", [$id]);
			$result->setFetchMode(PDO::FETCH_CLASS, $class);

			self::$_cache[ $class ][ $id ] = $result->fetch();
		}

		if (false == self::$_cache[ $class ][ $id ])
			throw new Basic_Entity_NotFoundException('Did not find `%s` with id `%d`', array($class, $id));

		return self::$_cache[ $class ][ $id ];
	}

	public static function create(array $data = array())
	{
		$entity = new static;
		$entity->save($data);

		// Reload all data from database
		return static::get($entity->id);
	}

	public function __get($key)
	{
		// `id` is private
		if ($key == 'id')
			return $this->id;

		if (method_exists($this, '_get'. ucfirst($key)))
			return call_user_func(array($this, '_get'. ucfirst($key)));

		if (property_exists($this, $key))
			return $this->$key;
	}

	public function __isset($key)
	{
		return (null !== $this->__get($key));
	}

	public function save(array $data = array())
	{
		if (array_key_exists('id', $data) && $data['id'] != $this->id)
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
		$data = array();
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
					throw new Basic_Entity_InvalidDataException('Value for `%s` contains invalid data `%s`', array($property, gettype($value)));
			}

			if ($value === $this->_dbData->$property || in_array($property, static::$_numerical) && $value == $this->_dbData->$property)
				continue;

			$data[ $property ] = $value;
		}

		if (empty($data))
			return false;

		if (isset($this->id))
		{
			$fields = implode('` = ?, `', array_keys($data));

			Basic::$database->query("UPDATE `". static::getTable() ."` SET `". $fields ."` = ? WHERE `id` = ?", array_merge(array_values($data), array($this->id)));
		}
		else
		{
			$columns = implode('`, `', array_keys($data));
			$values = implode(', :', array_keys($data));

			$query = Basic::$database->query("INSERT INTO `". static::getTable() ."` (`". $columns ."`) VALUES (:". $values .")", $data);

			if (1 != $query->rowCount())
				throw new Basic_Entity_StorageException('New `%s` could not be created', array(get_class($this)));

			$this->id = Basic::$database->lastInsertId();
		}

		unset(self::$_cache[ get_class($this) ][ $this->id ]);

		return true;
	}

	protected function _getProperties()
	{
		return array_diff(array_keys(get_object_vars($this)), array('id', '_dbData'));

		$properties = array();
		$object = new ReflectionObject($this);

		foreach ($object->getProperties(ReflectionProperty::IS_PUBLIC) as $property)
			array_push($properties, $property->getName());

		return $properties;
	}

	public static function find($filter = null, array $parameters = array(), array $order = null)
	{
		$class = get_called_class();

		if (!isset($order))
			$order = static::$_order;

//fixme: implement Basic_EntitySet::autoCreate like Exceptions?
		$setClass = $class.'Set';
		if (class_exists($setClass))
			return new $setClass($class, $filter, $parameters, $order);

		return new Basic_EntitySet($class, $filter, $parameters, $order);
	}

	public function delete()
	{
		$this->_checkPermissions('delete');
		unset(self::$_cache[ get_class($this) ][ $this->id ]);

		$result = Basic::$database->query("DELETE FROM `". static::getTable() ."` WHERE `id` = ". $this->id);

		if ($result != 1)
			throw new Basic_Entity_DeleteException('An error occured while deleting `%s`:`%s`', array(get_class($this), $this->id));
	}

	public static function getTable()
	{
		return array_pop(explode('_', get_called_class()));
	}

	protected function _checkPermissions($action)
	{
		return true;
	}

	public function setUserinputDefault()
	{
		foreach ($this->_getProperties() as $key)
		{
			if (!isset(Basic::$userinput->$key))
				continue;

			$value = isset(static::$_relations[$key]) ? $this->$key->id : $this->$key;

			try
			{
				$this->_setUserinputDefault($key, $value);
			}
			catch (Basic_UserinputValue_InvalidDefaultException $e)
			{
				if (!Basic::$config->PRODUCTION_MODE)
					throw $e;

				Basic::$log->write('InvalidDefaultException for `'. $key .'` on `'. get_class($this). '`, value = '. var_export($this->$key, true). ', caused by '. get_class($e->getPrevious()));
				// ignore, user cannot fix this
			}
		}
	}

	protected function _setUserinputDefault($key, $value)
	{
		Basic::$userinput->$key->default = $value;
	}

	public function getRelated($entityType)
	{
		$keys = array_keys($entityType::$_relations, get_class($this), true);

		if (1 != count($keys))
			throw new Basic_Entity_NoRelationFoundException('No relation of type `%s` was found', array($entityType));

		$key = array_pop($keys);

		return $entityType::find($key ." = ?", array($this->id));
	}

	public function getEnumValues($property)
	{
		$q = Basic::$database->query("SHOW COLUMNS FROM `". static::getTable() ."` WHERE field =  ?", array($property));
		return explode("','", str_replace(array("enum('", "')", "''"), array('', '', "'"), $q->fetchArray('Type')[0]));
	}
}