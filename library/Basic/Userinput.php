<?php

class Basic_Userinput
{
	private $_config;
	private $_validTypes = array('string', 'int', 'bool', 'array', 'numeric', 'bool');
	private $_validOptions = array(
		'minlength' => 'int',
		'maxlength' => 'int',
		'minvalue' => 'int',
		'maxvalue' => 'int',
		'pre_replace' => 'array',
		'post_replace' => 'array'
	);
	private $_globalInputs = array();
	private $_globalInputsValid = false;
	private $_actionInputs = array();
	private $_actionInputsValid = false;
	private $_details = array();

	public function __construct()
	{
		$this->_config = Basic::$config->Userinput;

		if (get_magic_quotes_gpc())
			$this->_undoMagicQuotes();
	}

	function init()
	{
		$this->_checkConfig();

		// Populate globally required_input
		foreach ($this->_config as $name => $config)
			if ('null' === $config['source']['action'])
				array_push($this->_globalInputs, $name);

		$this->_globalInputsValid = $this->_validateAll($this->_globalInputs);
	}

	function run()
	{
		if (isset(Basic::$action->userinput_config))
		{
			foreach (Basic::$action->userinput_config as $name => &$config)
			{
				if (!isset($config['source']['action']))
					$config['source']['action'] = array(Basic::$controller->action);

				$this->config[ $name ] =& $config;
			}

			$this->_checkConfig();
		}

		foreach ($this->_config as $name => $config)
			if (isset($config['source']['action']) && in_array(Basic::$controller->action, $config['source']['action']))
				array_push($this->_actionInputs, $name);

		$this->_actionInputsValid = $this->_validateAll($this->_actionInputs);
	}

	public function allInputValid()
	{
		return $this->_actionInputsValid && $this->_globalInputsValid;
	}

	// Globally strip slashes if necessary
	private function _undoMagicQuotes()
	{
		function stripslashes_deep($value)
		{
			return is_array($value) ? array_map('stripslashes_deep', $value) : (isset($value) ? stripslashes($value) : NULL);
		}

		$_POST = stripslashes_deep($_POST);
		$_GET = stripslashes_deep($_GET);
		$_COOKIE = stripslashes_deep($_COOKIE);
	}

	private function _checkConfig()
	{
		$default = array(
			'value_type' => 'string',
			'regexp' => null,
			'values' => null,
			'callback' => null,
		);

		foreach ($this->_config as $key => &$config)
		{
			$config['source'] += array(
				'action' => null,
				'superglobal' => 'POST',
				'key' => $key,
			);

			$config += $default;

			if (isset($config['source']['action']))
				settype($config['source']['action'], 'array');

			if (isset($config['values']) && !isset($config['input_type']))
				$config['input_type'] = 'select';

			settype($config['options'], 'array');

			$valid = array(
				'top' => isset($config['source']) && (isset($config['value_type']) || isset($config['regexp']) || isset($config['values']) || isset($config['callback'])),
				'action' => !isset($config['action']) || is_array($config['action']),
				'source_superglobal' => isset($GLOBALS[ '_'. $config['source']['superglobal'] ]),
				'source_key' => isset($config['source']['key']),
				'types' => (isset($config['value_type']) ? in_array($config['value_type'], $this->_validTypes, TRUE) : TRUE),
				'options' => true,
			);

			// Check the validity of the options and their types
			foreach(array_intersect_key($config['options'], $this->_validOptions) as $option_key => $option_value)
			{
				$validOption = TRUE;
				switch ($this->_validOptions[ $option_key ])
				{
					case 'string':	$validOption = is_string($option_value);	break;
					case 'array':	$validOption = is_array($option_value);		break;
					case 'int':		$validOption = is_int($option_value);		break;
				}

				$valid['options'] = $validOption;

				if (!$validOption)
					break;
			}

			if (array_sum($valid) != count($valid))
				throw new Basic_Userinput_InvalidConfigurationException('`%s` has an invalid configuration: %s', array($key, print_r($valid, true)));
		}
	}

	private function _validateAll($inputs)
	{
		foreach ($inputs as $name)
			if (!$this->isValid($name))
				return false;

		return true;
	}

	public function isValid($name)
	{
		if (!isset($this->_config[ $name ]))
			return false;

		return (!in_array('required', $this->_config[ $name ]['options']) || isset($this->$name));
	}

	public function __isset($name)
	{
		if (!isset($this->_details[ $name ]))
			$this->getDetails($name);

		return $this->_details[ $name ]['validates'];
	}

	public function __get($name)
	{
		if (!isset($this->_details[ $name ]))
			$this->getDetails($name);

		return ($this->_details[ $name ]['validates'] ? $this->_details[ $name ]['value'] : NULL);
	}

	public function getDetails($name)
	{
		if (!isset($this->_config[$name]))
			throw new Basic_Userinput_UndefinedException('The specified value `%s` is not configured', array($name));

		Basic::$log->start();

		$details = array();
		$config = $this->_config[$name];

		$source = $GLOBALS['_'. $config['source']['superglobal'] ];
		if (array_key_exists($config['source']['key'], $source))
		{
			$value = $source[ $config['source']['key'] ];
			$details['isset'] = true;
			$details['raw_value'] = $value;

			// Perform pre_replace regexp's before validating
			foreach ($config['options']['pre_replace'] as $preg => $replace)
				$value = preg_replace($preg, $replace, $value);

			$details['validates'] = $this->_validate($name, $value);

			if ($details['validates'])
				$value = $this->_clean($value);

			// Perform post_replace regexp's after validating
			foreach ($config['options']['post_replace'] as $preg => $replace)
				$value = preg_replace($preg, $replace, $value);

			$details['value'] = $value;
		}
		elseif (isset($config['default']))
		{
			$details['isset'] = TRUE;
			$details['value'] = $details['raw_value'] = $config['default'];
			$details['validates'] = $this->_validate($name, $details['value']);
		}
		else
			$details['isset'] = $details['validates'] = false;

		$details['value'] = $this->_clean($details['value']);

		$this->_details[ $name ] = $details;

		Basic::$log->end($name);

		return $this->_details[ $name ];
	}

	private function _clean($value)
	{
		$value = preg_replace('~(\r\n|\r)~', '\n', $value);

		// Firefox can only POST XMLHTTPRequests as UTF-8, see http://www.w3.org/TR/XMLHttpRequest/#send
		if (isset($_SERVER['CONTENT_TYPE']) && strtoupper(array_pop(explode('; charset=', $_SERVER['CONTENT_TYPE']))) == 'UTF-8')
			$value = mb_convert_encoding($value, 'ISO-8859-15', 'UTF-8');

		return $value;
	}

	private function _validate($name, $value)
	{
		$config = $this->_config[$name];

		switch($config['value_type'])
		{
			case 'string':	if (!is_string($value)) return false;		break;
			case 'array':	if (!is_array($value)) return false;		break;
			case 'numeric':	if (!is_numeric($value))return false;		break;

			default:
				throw new UserinputException('Unknown type `'. $config['value_type'] .'`');

			case null:
			break;
		}

		if (isset($config['values']))
		{
			if ($this->array_has_keys($config['values']))
			{
				$values = array();
				// Array-in-array?
				if (is_array(reset($config['values'])))
						foreach ($config['values'] as $_values)
							$values = array_merge($values, array_keys($_values));
				else
					$values = array_keys($config['values']);

				if (!in_array($value, $values))
					return false;
			}
			elseif (!in_array($value, $config['values']))
				return false;
		}

		if (isset($config['regexp']) && !preg_match($config['regexp'], $value))
			return false;

		if (isset($config['callback']) && !call_user_func($config['callback'], $value))
			return false;

		if (isset($config['options']['minlength']) && strlen($value) < $config['options']['minlength'])
			return false;

		if (isset($config['options']['maxlength']) && strlen($value) > $config['options']['maxlength'])
			return false;

		if (isset($config['options']['minvalue']) && intval($value) < $config['options']['minvalue'])
			return false;

		if (isset($config['options']['maxvalue']) && intval($value) > $config['options']['maxvalue'])
			return false;

		return true;
	}

	public function array_has_keys($array)
	{
		if (!is_array($array))
			return FALSE;

		$i = 0;
		foreach (array_keys($array) as $k)
			if ($k !== $i++)
				return TRUE;

		return FALSE;
	}

	public function set_default($name, $value)
	{
		$this->_config[ $name ]['default'] = $value;

		// Make sure the default get in $this->values as well
		$this->getDetails($name);
	}

	public function set_values($name, $values)
	{
		$this->_config[ $name ]['values'] = $values;
	}
}