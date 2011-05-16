<?php

class Basic_Entity implements ArrayAccess
{
	protected $id = null;
	protected static $_cache;
	protected $_data = array();
	protected $_table = null;
	protected $_relations = array();
	protected $_numerical = array();

	public function __construct($id = null)
	{
		if (!isset($this->_table))
			$this->_table = array_pop(explode('_', get_class($this)));

		if (isset($id) && !is_scalar($id))
			throw new Basic_Entity_InvalidIdException('Invalid type `%s` for `id`', array(gettype($id)));

		$this->id = $id;
	}

	function load($id = null)
	{
		Basic::$log->start();

		if (!isset($id))
			$id = $this->id;

		if (!isset(self::$_cache[$this->_table][$id]))
		{
			$query = Basic::$database->query("SELECT * FROM `". $this->_table ."` WHERE `id` = ?", array($id));

			if (0 == $query->rowCount())
				throw new Basic_Entity_NotFoundException('`%s` with id `%s` was not found', array(get_class($this), $id));

			self::$_cache[$this->_table][$id] = $query->fetch();
		}

		$this->_load(self::$_cache[$this->_table][$id]);

		Basic::$log->end($this->_table .':'. $id . (!isset($query)?' CACHED': ''));
	}

	// Public so the EntitySet can push results into the Entity
	public function _load(array $data)
	{
		foreach ($data as $key => $value)
		{
			$this->_data[$key] = $value;

			if (!isset($value))
				continue;

			if (array_key_exists($key, $this->_relations))
			{
				$class = $this->_relations[$key];
				$value = new $class($value);
			}
			elseif (in_array($key, $this->_numerical))
				$value = intval($value);
			elseif (':' == substr($value, 1, 1))
			{
				$_value = unserialize($value);

				if (!is_scalar($_value))
					$value = $_value;
			}

			$this->$key = $value;
		}

		// Checks might need a property, so do this after the actual loading
		$this->_checkPermissions('load');
	}

	public function __get($key)
	{
		// `id` is private
		if ($key == 'id')
			return $this->id;

		// Are we lazy-loaded?
		if (empty($this->_data))
		{
			$this->load();

			if (property_exists($this, $key))
				return $this->$key;
		}

		if (method_exists($this, '__get_'. $key))
			return call_user_func(array($this, '__get_'. $key));
	}

	public function __isset($key)
	{
		return ('id' == $key || method_exists($this, '__get_'. $key));
	}

	public function save($data = array())
	{
		if (array_key_exists('id', $data) && $data['id'] != $this->id)
			throw new Basic_Entity_InvalidDataException('You cannot change the `id` of an object');

		foreach ($data as $property => $value)
			$this->$property = $value;

		$this->_checkPermissions('save');

		$fields = $values = array();
		$properties = array_keys(!empty($this->_data) ? $this->_data : $data);
		foreach ($properties as $property)
		{
			$value = $this->$property;

			if (isset($value))
			{
				if ($value === '')
					$value = null;
				elseif (isset($this->_relations[$property]) && is_object($value))
					$value = $value->id;
				elseif (!is_scalar($value))
					$value = serialize($value);
			}

			if ($value === $this->_data[ $property ])
				continue;
			if (in_array($property, $this->_numerical) && $value == $this->_data[ $property ])
				continue;

			array_push($values, $value);
			array_push($fields, "`". $property ."` = ?");
		}
		$fields = implode(',', $fields);

		// No changes?
		if (empty($values))
			return;

		if (isset($this->id))
		{
			array_push($values, $this->id);

			$query = Basic::$database->query("UPDATE `". $this->_table ."` SET ". $fields ." WHERE `id` = ?", $values);
		}
		else
		{
			$query = Basic::$database->query("INSERT INTO `". $this->_table ."` SET ". $fields, $values);

			$this->id = Basic::$database->lastInsertId();
		}

		unset(self::$_cache[$this->_table][$this->id]);

		if ($query->rowCount() != 1)
			throw new Basic_Entity_StorageException('An error occured while creating/updating `%s`:`%s`', array(get_class($this), $this->id));
	}

	protected function _getProperties()
	{
		if (!empty($this->_data))
			return array_diff(array_keys($this->_data), array('id'));

		$properties = array();
		$object = new ReflectionObject($this);

		foreach ($object->getProperties(ReflectionProperty::IS_PUBLIC) as $property)
			array_push($properties, $property->getName());

		return $properties;
	}

	public static function find($filter = null, array $parameters = array(), $order = null, $limit = null)
	{
		return new Basic_EntitySet(get_called_class(), $filter, $parameters, $order, $limit);
	}

	public function delete()
	{
		$this->_checkPermissions('delete');

		$result = Basic::$database->query("
			DELETE FROM
				`". $this->_table ."`
			WHERE
				`id` = ". $this->id);

		unset(self::$_cache[$this->_table][$this->id]);

		if ($result != 1)
			throw new Basic_Entity_DeleteException('An error occured while deleting `%s`:`%s`', array(get_class($this), $this->id));
	}

	public function getTable()
	{
		return $this->_table;
	}

	protected function _checkPermissions($action)
	{
		return true;
	}

	public function setUserinputDefault()
	{
		$userinputConfig = Basic::$action->getUserinputConfig();

		foreach ($this->_getProperties() as $key)
		{
			if (isset($userinputConfig[ $key ]))
			{
				try
				{
					Basic::$userinput->$key->default = ($this->$key instanceof Basic_Entity) ? $this->$key->id : $this->$key;
				}
				catch (Basic_UserinputValue_InvalidDefaultException $e)
				{
					Basic::$log->write('InvalidDefaultException for `'. $key .'` on `'. get_class($this). '`, value = '. var_export($this->$key, true));
					// ignore, user cannot fix this
				}
			}
		}
	}

	public function getRelated($entityType)
	{
		$entity = new $entityType;

		$keys = array_keys($entity->_relations, get_class($this), true);

		if (1 != count($keys))
			throw new Basic_Entity_NoRelationFoundException('No relation of type `%s` was found', array($entityType));

		$key = array_pop($keys);

		return $entityType::find($key ." = ?", $this->id);
	}

	// For the templates
	public function offsetExists($offset){		return isset($this->$offset);					}
	public function offsetGet($offset){			return $this->$offset;							}
	public function offsetSet($offset,$value){	throw new Basic_Entity_UnsupportedException;	}
	public function offsetUnset($offset){		throw new Basic_Entity_UnsupportedException;	}
}