<?php

class Basic_UserinputValue
{
	protected $_name;
	protected $_config;
	protected $_validTypes = array('string', 'integer', 'boolean', 'array', 'numeric');
	protected $_validOptions = array(
		'minlength' => 'integer',
		'maxlength' => 'integer',
		'minvalue' => 'integer',
		'maxvalue' => 'integer',
		'pre_replace' => 'array',
		'pre_callback' => 'array',
		'post_replace' => 'array',
		'post_callback' => 'array',
	);

	public function __construct($name, $config)
	{
		$this->_name = $name;
		$this->_config = $this->_processConfig($config);
	}

	protected function _processConfig($config)
	{
		$default = array(
			'valueType' => 'string',
			'inputType' => 'text',
			'regexp' => null,
			'values' => null,
			'callback' => null,
			'required' => false,
			'options' => array(),
		);

		$config['source'] += array(
			'action' => null,
			'superglobal' => 'POST',
			'key' => $this->_name,
		);

		if (isset($config['values']) && !isset($config['inputType']))
			$config['inputType'] = 'select';

		$config += $default;

		if (isset($config['source']['action']))
			settype($config['source']['action'], 'array');

		if (isset($config['values']))
			settype($config['values'], 'array');

		settype($config['options'], 'array');
		settype($config['options']['pre_replace'], 'array');
		settype($config['options']['post_replace'], 'array');

		if (!isset($config['source']) || !(isset($config['valueType']) || isset($config['regexp']) || isset($config['values']) || isset($config['callback'])))
			throw new Basic_Userinput_ConfigurationInvalidException('`%s` is missing any one of `source`, `regexp`, `values`, `callback`', array($this->_name));

		if (!isset($GLOBALS[ '_'. $config['source']['superglobal'] ]))
			throw new Basic_Userinput_ConfigurationInvalidSuperglobalException('`%s` is using an unknown superglobal ``', array($this->_name, $config['source']['superglobal']));

		if (!isset($config['source']['key']))
			throw new Basic_Userinput_ConfigurationKeyMissingException('`%s` is missing a source-key', array($this->_name));

		if (isset($config['valueType']) && !in_array($config['valueType'], $this->_validTypes, true))
			throw new Basic_Userinput_ConfigurationValueTypeInvalidException('`%s` is using an invalid value-type `%s`', array($this->_name, $config['valueType']));

		// Check the validity of the options and their types
		foreach(array_intersect_key($config['options'], $this->_validOptions) as $option_key => $option_value)
			if (gettype($option_value) != $this->_validOptions[ $option_key ])
				throw new Basic_Userinput_ConfigurationInvalidOptionTypeException('`%s` is using an invalid option-type `%s` for option `%s`', array($this->_name, gettype($option_value), $option_key));

		return $config;
	}

	public function isValid()
	{
		return !$this->isRequired() || $this->isPresent();
	}

	// Former __isset
	public function isPresent($validate = true)
	{
		try
		{
			$this->_getRawValue();
			$isset = true;
		}
		catch (Basic_Userinput_NotPresentException $e)
		{
			$isset = false;
		}

		if (!$validate)
			return $isset;

		try
		{
			$this->getValue(false);
			$validates = true;
		}
		catch (Basic_Userinput_Validate_Exception $e)
		{
			$validates = false;
		}

		return $isset && $validates;
	}

	protected function _getRawValue()
	{
		$source = $GLOBALS['_'. $this->_config['source']['superglobal'] ];

		if (!array_key_exists($this->_config['source']['key'], $source))
			throw new Basic_Userinput_NotPresentException('This value is not present');

		return $source[ $this->_config['source']['key'] ];
	}

	// Former __get
	public function getValue($simple = true)
	{
		try
		{
			$value = $this->_getRawValue();
			$isset = true;
		}
		catch (Basic_Userinput_NotPresentException $e)
		{
			$isset = false;
		}

		if (!$isset)
		{
			$value = $this->_config['default'];
			$validates = $this->validate($value, $simple);
		}
		else
		{
			$value = str_replace(array("\r\n", "\r"), "\n", $value);

			// Firefox can only POST XMLHTTPRequests as UTF-8, see http://www.w3.org/TR/XMLHttpRequest/#send
			if (isset($_SERVER['CONTENT_TYPE']) && strtoupper(array_pop(explode('; charset=', $_SERVER['CONTENT_TYPE']))) == 'UTF-8')
				$value = self::_convertEncodingDeep($value);

			if ('file' == $this->_config['inputType'])
				$value = call_user_func(array($this, '_handleFile'), $value, $this->_name);

			if (isset($this->_config['options']['pre_callback']))
				$value = call_user_func($this->_config['options']['pre_callback'], $value, $this->_name);

			foreach ($this->_config['options']['pre_replace'] as $preg => $replace)
				$value = preg_replace($preg, $replace, $value);

			$validates = $this->validate($value, $simple);

			foreach ($this->_config['options']['post_replace'] as $preg => $replace)
				$value = preg_replace($preg, $replace, $value);

			if (isset($this->_config['options']['post_callback']))
				$value = call_user_func($this->_config['options']['post_callback'], $value, $this->_name);
		}

		return $validates ? $value : null;
	}

	public function isRequired()
	{
		// legacy
		if (!isset($this->_config['required']) && in_array('required', $this->_config['options'], true))
			return true;
//			throw new Basic_DeprecatedException('%s', array($this->_name));

		return $this->_config['required'];
	}

	public function isGlobal()
	{
		return (null == $this->_config['source']['action']);
	}

	public function setDefault($value)
	{
		if (($value === null && $this->isRequired()) || ($value !== null && !$this->validate($value)))
			throw new Basic_Userinput_InvalidDefaultException('Invalid default value `%s` for `%s`', array($value, $this->_name));

		$this->_config['default'] = $value;
	}

	public function setValues(array $values)
	{
		$this->_config['values'] = $values;
	}

	public function setRequired($state = true)
	{
		$this->_config['required'] = $state;
	}

	public function getConfig()
	{
		return $this->_config;
	}

	public function getFormData()
	{
		Basic::$log->start();

		try
		{
			$rawValue = $this->_getRawValue();
		}
		catch (Basic_Userinput_NotPresentException $e)
		{
			$rawValue = $this->_config['default'];
		}

		try
		{
			$this->getValue(false);
			$validates = true;
		}
		catch (Basic_Userinput_Validate_Exception $e)
		{
			$validates = false;
		}

		$details = array(
			'rawValue' => $rawValue,
			'validates' => $validates,
		);

		Basic::$log->end($this->_name);

		return $details;
	}

	public function validate($value, $simple = true)
	{
		try
		{
			return $this->_validate($value);
		}
		catch (Basic_Userinput_Validate_Exception $e)
		{
			if ($simple)
				return false;

			throw $e;
		}
	}

	// For the templates
	public function __toString()
	{
		return (string)$this->getValue();
	}

	protected function _validate($value)
	{
		$isValid = true;
		if ($this->_config['valueType'] == 'string')
			$isValid = is_string($value);
		elseif ($this->_config['valueType'] == 'array')
			$isValid = is_array($value);
		elseif ($this->_config['valueType'] == 'numeric')
			$isValid = is_numeric($value);
		elseif ($this->_config['valueType'] == 'integer')
			$isValid = (intval($value) == $value);
		elseif (isset($this->_config['valueType']))
			throw new Basic_Userinput_UnknownValueTypeException('Unknown type `%s`', array($this->_config['valueType']));

		if (!$isValid)
			throw new Basic_Userinput_Validate_InvalidValueTypeException('Expected type `%s` but found `%s`', array($this->_config['valueType'], gettype($value)));

		if (isset($this->_config['values']))
		{
			if (array_has_keys($this->_config['values']))
			{
				$values = array();

				foreach ($this->_config['values'] as $name => $_value)
				{
					if (is_array($_value))
						$values = array_merge($values, array_keys($_value));
					else
						array_push($values, $name);
				}

				// Multiple values?
				if (is_array($value))
				{
					foreach ($value as $_value)
						if (!in_array($_value, $values))
							throw new Basic_Userinput_Validate_ArrayValueException('Unknown value `%s`', array($_value));

				}
				elseif (!in_array($value, $values))
					throw new Basic_Userinput_Validate_ArrayValueException('Unknown value `%s`', array($value));
			}
			elseif (!in_array($value, $this->_config['values']))
				throw new Basic_Userinput_Validate_ArrayValueException('Unknown value `%s`', array($value));
		}

		if (isset($this->_config['regexp']) && !preg_match($this->_config['regexp'], $value))
			throw new Basic_Userinput_Validate_RegexpException('Value `%s` does not match specified regular expression', array($value));

		if (isset($this->_config['callback']) && !call_user_func($this->_config['callback'], $value))
			throw new Basic_Userinput_Validate_CallbackException('Callback did not validate `%s`', array($value));

		if (isset($this->_config['options']['minlength']) && strlen($value) < $this->_config['options']['minlength'])
			throw new Basic_Userinput_Validate_MinimumLengthException('Value is too short `%s`', array($value));

		if (isset($this->_config['options']['maxlength']) && strlen($value) > $this->_config['options']['maxlength'])
			throw new Basic_Userinput_Validate_MaximumLengthException('Value is too long `%s`', array($value));

		if (isset($this->_config['options']['minvalue']) && intval($value) < $this->_config['options']['minvalue'])
			throw new Basic_Userinput_Validate_MinimumValueException('Value is too low `%s`', array($value));

		if (isset($this->_config['options']['maxvalue']) && intval($value) > $this->_config['options']['maxvalue'])
			throw new Basic_Userinput_Validate_MaximumValueException('Value is too high `%s`', array($value));

		return true;
	}

	// This is a forced pre_callback for file-inputs
	protected function _handleFile($value, $name)
	{
		// Returning the default value means the entry won't be emptied
		if ($value['error'] == UPLOAD_ERR_NO_FILE)
			return null;
//			return ifsetor($this->_config->{$name}['default'], null);

		if ($value['error'] != UPLOAD_ERR_OK)
			throw new Basic_Userinput_UploadedFileException('An error `%s` occured while processing the file you uploaded'. array($value['error']));

		$finfo = new finfo(FILEINFO_MIME);

		if (!$finfo)
			throw new Basic_Userinput_FileInfoException('Could not open fileinfo-database');

		$mime = $finfo->file($value['tmp_name']);

		if (false !== strpos($mime, ';'))
			$mime = array_shift(explode(';', $mime));

		if (!in_array($mime, $this->_config->{$name}['options']['mimetypes']))
			throw new Basic_Userinput_FileInvalidMimeTypeException('The uploaded file has an invalid MIME type `%s`', array($mime));

		$newName = $this->_config->{$name}['options']['path'] . sha1_file($value['tmp_name']) .'.'. array_pop(explode('/', $mime));

		if (file_exists($newName))
			unlink($value['tmp_name']);
		else
		{
			if (!move_uploaded_file($value['tmp_name'], $newName))
				throw new Basic_Userinput_CouldNotMoveFileException('Could not move the uploaded file to its target path `%s`', array($this->_config->{$name}['options']['path']));
		}

		// We do not need the full path in the database
		return basename($newName);
	}

	protected static function _convertEncodingDeep($value)
	{
		if ('UTF-8' == Basic::$action->encoding)
			return $value;

		return is_array($value) ? array_map(array(self, '_convertEncodingDeep'), $value) : (isset($value) ? mb_convert_encoding($value, Basic::$action->encoding, 'UTF-8') : NULL);
	}
}