<?php

class Basic_Entity implements ArrayAccess
{
	protected $id = 0;
	protected $_set;

	protected $_data = array();
	protected $_table = NULL;

	public function __construct($id = 0)
	{
		if (!is_numeric($id))
			throw new Basic_Entity_InvalidIdException('`%s` is not numeric', array($id));
		else
			$id = (int)$id;

		if (!isset($this->_table))
			$this->_table = array_pop(explode('_', get_class($this)));

		if ($id != 0)
			$this->load($id);
	}

	function load($id = NULL)
	{
		if (!isset($id))
			$id = $this->id;

		$query = Basic::$database->query("SELECT * FROM `". $this->_table ."` WHERE `id` = ?", array($id));

		if (0 == $query->rowCount())
			throw new Basic_Entity_NotFoundException('`%s` with id `%d` was not found', array(get_class($this), $id));

		$this->_load($query->fetch());
	}

	// Public so the Database can push results into the Model
	public function _load($data)
	{
		$this->_data = $data;

		foreach ($this->_data as $key => $value)
			$this->$key = $value;

		$this->id = (int)$this->id;

		// Checks might need a property, so do this after the actual loading
		$this->checkPermissions('load');
	}

	public function __get($key)
	{
		// This is private
		if ($key == 'id')
			return $this->id;

		if (method_exists($this, '__get_'. $key))
			return call_user_func(array($this, '__get_'. $key));
	}

	public function __isset($key)
	{
		return ('id' == $key || method_exists($this, '__get_'. $key));
	}

	public function save($data = array())
	{
		if ((array_key_exists('id', $data) && $data['id'] != $this->id))
			throw new Basic_Entity_InvalidDataException('You cannot update the id of an object');

		foreach ($data as $property => $value)
			$this->$property = $value;

		$fields = array();
		foreach ($this->getProperties() as $property)
		{
			// Convert empty strings to NULLs
			if ($this->$property === '')
				$this->$property = null;

			// These issets actually check for NULL values [not anymore]
			// No strict checking, database will return ints as strings
			if ($this->$property != $this->_data[ $property ] || (!array_key_exists($property, $this->_data) && property_exists($this, $property)))
				array_push($fields, $property);
		}

		if (count($fields) > 0)
			$this->_save($fields);
	}

	public function getProperties()
	{
		if (!empty($this->_data))
			return array_diff(array_keys($this->_data), array('id'));

		$properties = array();
		$object = new ReflectionObject($this);

		foreach ($object->getProperties(ReflectionProperty::IS_PUBLIC) as $property)
			array_push($properties, $property->getName());

		return $properties;
	}

	protected function _save(array $modified)
	{
		if ($this->id > 0)
			$this->checkPermissions('store');

		$fields = $values = array();
		foreach ($modified as $key)
		{
			$value = $this->$key;

			if (is_array($value))
				$value = serialize($value);

			array_push($values, $value);
			array_push($fields, "`". $key ."` = ?");
		}
		$fields = implode(',', $fields);

		if ($this->id > 0)
		{
			array_push($values, $this->id);

			$query = Basic::$database->query("
				UPDATE
					`". $this->_table ."`
				SET
					". $fields ."
				WHERE
					`id` = ?", $values);
		} else {
			$query = Basic::$database->query("
				INSERT INTO
					`". $this->_table ."`
				SET
					". $fields, $values);

			$this->id = Basic::$database->lastInsertId();
		}

		if ($query->rowCount() != 1)
			throw new Basic_Entity_StorageException('An error occured while creating/updating the object');

		return (bool)$query->rowCount();
	}

	public static function find($filter = null, array $parameters = array(), $order = null, $limit = null)
	{
		return new Basic_EntitySet(get_called_class(), $filter, $parameters, $order, $limit);
	}

	public function delete()
	{
		$this->checkPermissions('delete');

		return (bool) Basic::$database->query("
			DELETE FROM
				`". $this->_table ."`
			WHERE
				`id` = ". $this->id);
	}

	public function getTable()
	{
		return $this->_table;
	}

	public function checkPermissions($action)
	{
		return true;
	}

	public function setUserinputDefault()
	{
		$userinputConfig = Basic::$action->getUserinputConfig();

		foreach ($this->getProperties() as $key)
		{
			if (isset($userinputConfig[ $key ]))
			{
				try
				{
					Basic::$userinput->$key->default = $this->$key;
				}
				catch (Basic_UserinputValue_InvalidDefaultException $e)
				{
					// ignore, user cannot fix this
				}
			}
		}
	}

	public function _createDb()
	{
		echo '<pre>CREATE TABLE IF NOT EXISTS `'. $this->_table .'`('."\n";
		$columns = array('id' => 'int(11) UNSIGNED AUTO_INCREMENT');

		foreach (Basic::$action->userinputConfig as $k => $c)
		{
			if (!isset($c['source']['action']) || $c['source']['action'] != array(Basic::$controller->action))
				continue;

			if ($c['inputType'] == 'select')
				$columns[ $k ] = 'ENUM(\''. implode('\',\'', array_keys($c['values'])) .'\')';
			elseif ($c['inputType'] == 'date')
				$columns[ $k ] = 'DATE';
			elseif ($c['inputType'] == 'radio')
				$columns[ $k ] = 'INT(1) UNSIGNED';
			elseif (isset($c['valueType']) && $c['valueType'] == 'string')
				$columns[ $k ] = 'varchar(255)';
			else
				die(var_dump($k, 'unknown valueType', $c));
		}

		foreach ($columns as $k => $c)
			echo '`'. $k .'` '. $c .' NOT NULL,'."\n";

		echo 'PRIMARY KEY (`id`) ) ENGINE=InnoDB';

		echo '</pre>';

		die;
	}

	// For the templates
	public function offsetExists($offset){		return isset($this->$offset);	}
	public function offsetGet($offset){			return $this->$offset;			}
	public function offsetSet($offset,$value){	return $this->$offset = $value;	}
	public function offsetUnset($offset){		unset($this->$offset);			}
}