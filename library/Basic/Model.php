<?php

class Basic_Model
{
	private $id = 0;
	private $_modified = array();

	protected $_data;
	protected $_table = NULL;
	protected $_key = NULL;

	function __construct($id = 0)
	{
		if (!is_numeric($id))
			throw new ModelException('invalid_id');
		else
			$id = (int)$id;

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
				`". $this->_key ."` = ". $id);

		if ($result == 0)
			throw new ModelException('object_not_found');

		$this->_load(Basic::$database->fetchNext());
	}

	// Public so the Database can push results into the Model
	public function _load($data)
	{
		$this->_data = $data;

		$this->id = (int)$this->_data[ $this->_key ];
		unset($this->_data[ $this->_key ]);

		foreach ($this->_data as $key => $value)
			$this->$key = $value;

		// Checks might need a property, so do this after the actual loading
		$this->check_permissions('load');
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
		if ((isset($data['id']) && $data['id'] != $this->id) || isset($data[ $this->_key ]))
			throw new ModelException('cannot_change_id');

		foreach ($data as $key => $value)
			$this->$key = $value;

		$object = new ReflectionObject($this);

		$this->_modified = array();
		foreach ($object->getProperties(ReflectionProperty::IS_PUBLIC) as $property)
		{
			$key = $property->getName();

			if ($this->$key != $this->_data[ $key ])
				array_push($this->_modified, $key);
		}

		if (count($this->_modified) > 0)
			$this->_save();
	}

	protected function _save()
	{
		if ($this->id > 0)
			$this->check_permissions('store');

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
					`". $this->_key ."` = ". $this->id);
		} else {
			$rows = Basic::$database->query("
				INSERT INTO
					`". $this->_table ."`
				SET
					". $fields);

			if ($rows != 1)
				throw new ModelException('error_inserting_object');

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
					throw new ModelException('no_possible_results');

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
		try {
			$where = self::parse_filters($filters);
		} catch (ModelException $e) {
			if ($e->getMessage == 'no_possible_results')
				return array();

			throw $e;
		}

		$this->database->query("
			SELECT
				*
			FROM
				`". $this->_table ."`
			WHERE
				". $where);

		return $this->database->fetch_all_objects(get_class($this));
	}

	public static function find($classname, $filters = array())
	{
		$object = new $classname();
		return $object->_find($filters);
	}

	public function delete()
	{
		$this->check_permissions('delete');

		$result = Basic::$database->query("
			DELETE FROM
				`". $this->_table ."`
			WHERE
				`". $this->_key ."` = ". $this->id);

		return (boolean)$result;
	}

	public function check_permissions($action)
	{
		return TRUE;
	}

	public function set_userinput_default()
	{
		foreach (array_keys($this->_data) as $key)
			if (isset(Basic::$action->userinputConfig[$key]))
				Basic::$userinput->setDefault($key, $this->$key);
	}

	public function _createDb()
	{
		echo '<pre>CREATE TABLE IF NOT EXISTS `'. $this->_table .'`('."\n";
		$columns = array($this->_key => 'int(11) UNSIGNED AUTO_INCREMENT');

		foreach (Basic::$action->userinputConfig as $k => $c)
		{
			if (!isset($c['source']['action']) || $c['source']['action'] != array($this->engine->action))
				continue;

			if (isset($c['value_type']) && $c['value_type'] == 'string')
				$columns[ $k ] = 'varchar(255)';
			elseif ($c['input_type'] == 'select')
				$columns[ $k ] = 'ENUM(\''. implode('\',\'', array_keys($c['values'])) .'\')';
			elseif ($c['input_type'] == 'calendar')
				$columns[ $k ] = 'DATE';
			elseif ($c['input_type'] == 'radio')
				$columns[ $k ] = 'INT(1) UNSIGNED';
			else
				die(var_dump($k, 'unknown value_type', $c));
		}

		foreach ($columns as $k => $c)
			echo '`'. $k .'` '. $c .' NOT NULL,'."\n";

		echo 'PRIMARY KEY (`'. $this->_key .'`) ) ENGINE=InnoDB';

		echo '</pre>';

		die;
	}
}