<?php

class Basic_Model implements ArrayAccess
{
	private $id = 0;
	private $_modified = array();

	protected $_data;
	protected $_table = NULL;

	function __construct($id = 0)
	{
		if (!is_numeric($id))
			throw new Basic_Model_InvalidIdException('`%s` is not numeric', array($id));
		else
			$id = (int)$id;

		if (!isset($this->_table))
			$this->_table = array_pop(explode('_', get_class($this)));

		if ($id > 0)
			$this->load($id);
	}

	function load($id = NULL)
	{
		if (!isset($id))
			$id = $this->id;

		$result = Basic::$database->query("
			SELECT
				*
			FROM
				`". $this->_table ."`
			WHERE
				`id` = ". $id);

		if ($result == 0)
			throw new Basic_Model_NotFoundException('`%s` with id `%d` was not found', array(get_class($this), $id));

		$this->_load(Basic::$database->fetchNext());
	}

	// Public so the Database can push results into the Model
	public function _load($data)
	{
		$this->_data = $data;

		foreach ($this->_data as $key => $value)
			$this->$key = $value;

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

	public function store($data = array())
	{
		return $this->save($data);
	}

	public function save($data = array())
	{
		if ((isset($data['id']) && $data['id'] != $this->id))
			throw new Basic_Model_InvalidDataException('You cannot update the id of an object');

		foreach ($data as $property => $value)
			$this->$property = $value;

		$this->_modified = array();
		foreach ($this->_getProperties() as $property)
		{
			if ($this->$property != $this->_data[ $property ])
				array_push($this->_modified, $property);
		}

		if (count($this->_modified) > 0)
			$this->_save();
	}

	protected function _getProperties()
	{
		static $properties = array();

		if (empty($properties))
		{
			$object = new ReflectionObject($this);

			foreach ($object->getProperties(ReflectionProperty::IS_PUBLIC) as $property)
				array_push($properties, $property->getName());
		}

		return $properties;
	}

	protected function _save()
	{
		if ($this->id > 0)
			$this->checkPermissions('store');

		$fields = array();
		foreach ($this->_modified as $key)
		{
			$value = $this->$key;

			// Make sure any
			unset($this->$key);

			if (is_int($value))
				array_push($fields, "`". $key ."` = ". $value);
			elseif (is_array($value))
				array_push($fields, "`". $key ."` = '". Basic_Database::escape(serialize($value)) ."'");
			else
				array_push($fields, "`". $key ."` = '". Basic_Database::escape($value) ."'");
		}
		$fields = implode(',', $fields);

		if ($this->id > 0)
		{
			$rows = Basic::$database->query("
				UPDATE
					`". $this->_table ."`
				SET
					". $fields ."
				WHERE
					`id` = ". $this->id);
		} else {
			$rows = Basic::$database->query("
				INSERT INTO
					`". $this->_table ."`
				SET
					". $fields);

			if ($rows != 1)
				throw new Basic_Model_StorageException('An error occured while creating the object');

			$this->id = mysql_insert_id();
		}
	}

	public static function parse_filters($filters)
	{
		$sql = array();

		foreach ($filters as $key => $value)
		{
			if (substr($key, 0, 2) == '!%')
				$statement = "`". substr($key, 1) ."` NOT LIKE ";
			elseif ($key{0} == '!')
				$statement = "`". substr($key, 1) ."` != ";
			elseif ($key{0} == '%')
				$statement = "`". substr($key, 1) ."` LIKE ";
			else
				$statement = "`". substr($key, 1) ."` = ";

			if (is_array($value))
			{
				if (0 == count($value))
					throw new Basic_Model_ImpossibleFilterException('You specified a filter that cannot match anything');

				$_values = array();
				foreach ($value as $_value)
					array_push($_values, (is_int($_value) ? $_value : "'". Basic_Database::escape($_value). "'"));

				array_push($sql, "`". $key ."` IN (". implode(",", $_values) .")");
			}
			elseif (is_int($value))
				array_push($sql, "`". $key ."` = ". $value);
			elseif ($key{0} == '%')
				array_push($sql, "`". substr($key, 1) ."` LIKE '". Basic_Database::escape($value) ."'");
			elseif ($key{0} == '!')
				array_push($sql, "`". substr($key, 1) ."` != '". Basic_Database::escape($value) ."'");
			else
				array_push($sql, "`". $key ."` = '". Basic_Database::escape($value) ."'");
		}

		return implode(" AND ", $sql);
	}

	public function _find($filters)
	{
		try
		{
			$where = self::parse_filters($filters);
		}
		catch (Basic_Model_ImpossibleFilterException $e)
		{
			return array();
		}

		if (!empty($where))
			$where = "WHERE ". $where;

		Basic::$database->query("
			SELECT
				*
			FROM
				`". $this->_table ."`
				". $where);

		return Basic::$database->fetchAllObjects(get_class($this));
	}

	public static function find($classname, $filters = array())
	{
		$object = new $classname();
		return $object->_find($filters);
	}

	public function delete()
	{
		$this->checkPermissions('delete');

		$result = Basic::$database->query("
			DELETE FROM
				`". $this->_table ."`
			WHERE
				`id` = ". $this->id);

		return (boolean)$result;
	}

	public function checkPermissions($action)
	{
		return TRUE;
	}

	public function setUserinputDefault()
	{
		foreach ($this->_getProperties() as $key)
			if (isset(Basic::$action->userinputConfig[$key]))
				Basic::$userinput->setDefault($key, $this->$key);
	}

	public function _createDb()
	{
		echo '<pre>CREATE TABLE IF NOT EXISTS `'. $this->_table .'`('."\n";
		$columns = array('id' => 'int(11) UNSIGNED AUTO_INCREMENT');

		foreach (Basic::$action->userinputConfig as $k => $c)
		{
			if (!isset($c['source']['action']) || $c['source']['action'] != array(Basic::$controller->action))
				continue;

			if ($c['input_type'] == 'select')
				$columns[ $k ] = 'ENUM(\''. implode('\',\'', array_keys($c['values'])) .'\')';
			elseif ($c['input_type'] == 'date')
				$columns[ $k ] = 'DATE';
			elseif ($c['input_type'] == 'radio')
				$columns[ $k ] = 'INT(1) UNSIGNED';
			elseif (isset($c['value_type']) && $c['value_type'] == 'string')
				$columns[ $k ] = 'varchar(255)';
			else
				die(var_dump($k, 'unknown value_type', $c));
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