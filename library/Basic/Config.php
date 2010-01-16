<?php

class Basic_Config
{
	private $_path;

	public function __construct()
	{
		$this->init(APPLICATION_PATH .'/config.ini');
	}

	public function init($path)
	{
		$this->_path = $path;

		$_config = parse_ini_file($this->_path, true);

		// Now cast to objects
		foreach ($_config as $key => $value)
			if (is_array($value))
			{
				foreach ($value as $_key => $_value)
					$config->$key->$_key = $_value;
			} else
				$config->$key = $value;

		$config = $this->_processKeys($config);

		foreach ($config as $key => $value)
			$this->$key = $value;
	}

	// Find keys with a colon in them; and make entries out of them
	private function _processKeys($config)
	{
		foreach ($config as $key => $values)
		{
			if (false == strpos($key, ':'))
				continue;

			$pointer =& $config;
			foreach (explode(':', $key) as $part)
			{
//				if (!isset($pointer->$part))
//					$pointer->$part = new StdClass;

				$pointer =& $pointer->$part;
			}

			$pointer = $values;
			unset($config->$key);
		}

		return $config;
	}
}