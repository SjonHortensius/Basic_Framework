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

		$config = parse_ini_file($this->_path, true);

		$config = $this->_processKeys($config);

		foreach ($config as $key => $value)
			$this->$key = $value;
	}

	// Find keys with a colon in them; and make arrays out of that
	private function _processKeys($config)
	{
		foreach ($config as $key => $values)
		{
			if (false == strpos($key, ':'))
				continue;

			$pointer =& $config;
			foreach (explode(':', $key) as $part)
			{
				if (!isset($pointer[ $part ]))
					$pointer[ $part ] = array();

				$pointer =& $pointer[ $part ];
			}

			$pointer = $values;
		}

		unset($config[ $key ]);

		return $config;
	}
}