<?php

class Basic_UserinputValue
{
	protected $_name;
	protected $_config;
	protected $_validTypes = array('scalar', 'integer', 'boolean', 'array', 'numeric');
	protected $_validOptions = array(
		'minlength' => 'integer',
		'maxlength' => 'integer',
		'minvalue' => 'integer',
		'maxvalue' => 'integer',
		'pre_replace' => 'array',
		'post_replace' => 'array',
	);
	protected $_fileLocation;

	public function __construct($name, array $config)
	{
		$this->_name = $name;
		$this->_config = $this->_processConfig($config);
	}

	protected function _processConfig($config)
	{
		$default = array(
			'valueType' => 'scalar',
			'regexp' => null,
			'values' => null,
			'callback' => null,
			'required' => false,
			'options' => array(),
		);

		settype($config['source'], 'array');

		$config['source'] += array(
			'superglobal' => 'POST',
			'key' => $this->_name,
		);

		if (!isset($config['inputType']) && in_array($config['source']['superglobal'], array('POST', 'FILES')))
		{
			if (isset($config['values']))
				$config['inputType'] = 'select';
			else
				$config['inputType'] = 'text';
		}

		$config += $default;

		if (isset($config['values']))
			settype($config['values'], 'array');

		settype($config['options'], 'array');
		settype($config['options']['pre_replace'], 'array');
		settype($config['options']['post_replace'], 'array');

		if (!isset($config['source']) || !(isset($config['valueType']) || isset($config['regexp']) || isset($config['values']) || isset($config['callback'])))
			throw new Basic_UserinputValue_ConfigurationInvalidException('`%s` is missing any one of `source`, `regexp`, `values`, `callback`', array($this->_name));

		if (!isset($GLOBALS[ '_'. $config['source']['superglobal'] ]))
			throw new Basic_UserinputValue_ConfigurationInvalidSuperglobalException('`%s` is using an unknown superglobal ``', array($this->_name, $config['source']['superglobal']));

		if (!isset($config['source']['key']))
			throw new Basic_UserinputValue_ConfigurationKeyMissingException('`%s` is missing a source-key', array($this->_name));

		if (!is_bool($config['required']))
			throw new Basic_UserinputValue_ConfigurationRequiredFormatException('`%s` has incorrect value for `required`', array($this->_name));

		if (isset($config['valueType']) && !in_array($config['valueType'], $this->_validTypes, true))
			throw new Basic_UserinputValue_ConfigurationValueTypeInvalidException('`%s` is using an invalid value-type `%s`', array($this->_name, $config['valueType']));

		// Check the validity of the options and their types
		foreach(array_intersect_key($config['options'], $this->_validOptions) as $option_key => $option_value)
			if (gettype($option_value) != $this->_validOptions[ $option_key ])
				throw new Basic_UserinputValue_ConfigurationInvalidOptionTypeException('`%s` is using an invalid option-type `%s` for option `%s`', array($this->_name, gettype($option_value), $option_key));

		return $config;
	}

	public function isValid()
	{
		return !$this->isRequired() || $this->isPresent();
	}

	public function isPresent($validate = true)
	{
		try
		{
			$this->getRawValue();
			$isset = true;
		}
		catch (Basic_UserinputValue_NotPresentException $e)
		{
			$isset = false;
		}

		if (!$validate || !$isset)
			return $isset;

		try
		{
			$this->getValue(false);
			$validates = true;
		}
		catch (Basic_UserinputValue_Validate_Exception $e)
		{
			$validates = false;
		}

		return $isset && $validates;
	}

	public function getRawValue()
	{
		$source = $GLOBALS['_'. $this->_config['source']['superglobal'] ];

		if (!array_key_exists($this->_config['source']['key'], $source))
			throw new Basic_UserinputValue_NotPresentException('This value is not present');

		return $source[ $this->_config['source']['key'] ];
	}

	public function getValue($simple = true)
	{
		try
		{
			$value = $this->getRawValue();
			$isset = true;
		}
		catch (Basic_UserinputValue_NotPresentException $e)
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
		return $this->_config['required'];
	}

	public function isGlobal()
	{
		return isset(Basic::$config->Userinput->{$this->_name});
	}

	public function getHtml()
	{
		if (!isset($this->_config['inputType']))
			return;

		Basic::$log->start();

		$classParts = array_map('ucfirst', explode('_', Basic::$controller->action));
		$paths = $rowPaths = array();

		do
		{
			$paths = array_merge(
				$paths,
				array(
					'Userinput'. (empty($classParts) ? '' : '/'. implode('/', $classParts)) .'/Name/'. $this->_name,
					'Userinput'. (empty($classParts) ? '' : '/'. implode('/', $classParts)) .'/Type/'. $this->_config['inputType'],
					'Userinput'. (empty($classParts) ? '' : '/'. implode('/', $classParts)) .'/Input',
				)
			);

			array_push($rowPaths, 'Userinput'. (empty($classParts) ? '' : '/'. implode('/', $classParts)) .'/Row');
		}
		while (null !== array_pop($classParts));

		Basic::$template->input = $this;

		try
		{
			Basic::$template->rawValue = $this->getRawValue();
		}
		catch (Basic_UserinputValue_NotPresentException $e)
		{
			Basic::$template->rawValue = $this->_config['default'];
		}

		try
		{
			$this->getValue(false);
			$validates = true;
		}
		catch (Basic_UserinputValue_Validate_Exception $e)
		{
			$validates = false;
		}

		// Determine the state of the input
		if (!$this->isPresent(false) || ($validates || !$this->_config['required']))
			Basic::$template->state = 'valid';
		else
			Basic::$template->state = 'invalid';

		Basic::$log->end($this->_name);

		Basic::$template->userInputHtml = Basic::$template->showFirstFound($paths, TEMPLATE_RETURN_STRING);

		return Basic::$template->showFirstFound($rowPaths, TEMPLATE_RETURN_STRING);
	}

	public function validate($value, $simple = true)
	{
		try
		{
			return $this->_validate($value);
		}
		catch (Basic_UserinputValue_Validate_Exception $e)
		{
			if ($simple)
				return false;

			throw $e;
		}
	}

	public function __get($key)
	{
		$value = $this->_config[ $key ];

		switch ($key)
		{
			case 'values':
				if (in_array('valuesToKeys', $this->_config['options'], true) && isset($value))
					return array_combine($value, $value);
			// fallthrough
			default:
				return $value;
		}
	}

	public function __isset($key)
	{
		return isset($this->_config[ $key ]);
	}

	public function __set($key, $value)
	{
		if ('default' == $key)
		{
			if ($value === null && $this->isRequired())
				throw new Basic_UserinputValue_InvalidDefaultException('Invalid default value `%s` for `%s`', array('NULL', $this->_name));
			elseif ($value !== null)
			{
				try
				{
					$this->validate($value, false);
				}
				catch (Basic_UserinputValue_Validate_Exception $e)
				{
					throw new Basic_UserinputValue_InvalidDefaultException('Invalid default value `%s` for `%s`', array($value, $this->_name), 0, $e);
				}
			}
		}

		$config = $this->_processConfig(array_merge($this->_config, array($key => $value)));

		$this->_config[ $key ] = $config[ $key ];
	}

	protected function _validate($value)
	{
		$isValid = true;
		if ($this->_config['valueType'] == 'scalar')
			$isValid = is_scalar($value);
		elseif ($this->_config['valueType'] == 'array')
			$isValid = is_array($value);
		elseif ($this->_config['valueType'] == 'numeric')
			$isValid = is_numeric($value);
		elseif ($this->_config['valueType'] == 'integer')
			$isValid = (strlen($value) == strspn($value, '0123456789'));
		elseif (isset($this->_config['valueType']))
			throw new Basic_UserinputValue_UnknownValueTypeException('Unknown type `%s`', array($this->_config['valueType']));

		if (!$isValid)
			throw new Basic_UserinputValue_Validate_InvalidValueTypeException('Expected type `%s` but found `%s`', array($this->_config['valueType'], gettype($value)));

		if (isset($this->_config['values']))
		{
			//FIXME: values=array('waa') will not complain for $value='meukee';
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
				foreach ($value as $_key => $_value)
					if (!in_array($_value, $values))
						throw new Basic_UserinputValue_Validate_ArrayValueException('Unknown value `%s`', array($_key .'->'. $_value));
			}
			elseif (!in_array($value, $values))
				throw new Basic_UserinputValue_Validate_ArrayValueException('Unknown value `%s`', array($value));
		}

		if (isset($this->_config['regexp']) && !preg_match($this->_config['regexp'], $value))
			throw new Basic_UserinputValue_Validate_RegexpException('Value `%s` does not match specified regular expression', array($value));

		if (isset($this->_config['callback']) && !call_user_func($this->_config['callback'], $value))
			throw new Basic_UserinputValue_Validate_CallbackException('Callback did not validate `%s`', array($value));

		if (isset($this->_config['options']['minlength']) && mb_strlen($value) < $this->_config['options']['minlength'])
			throw new Basic_UserinputValue_Validate_MinimumLengthException('Value is too short `%s`', array($value));

		if (isset($this->_config['options']['maxlength']) && mb_strlen($value) > $this->_config['options']['maxlength'])
			throw new Basic_UserinputValue_Validate_MaximumLengthException('Value is too long `%s`', array($value));

		if (isset($this->_config['options']['minvalue']) && intval($value) < $this->_config['options']['minvalue'])
			throw new Basic_UserinputValue_Validate_MinimumValueException('Value is too low `%s`', array($value));

		if (isset($this->_config['options']['maxvalue']) && intval($value) > $this->_config['options']['maxvalue'])
			throw new Basic_UserinputValue_Validate_MaximumValueException('Value is too high `%s`', array($value));

		return true;
	}

	// This is a forced pre_callback for file-inputs
	protected function _handleFile($value, $name)
	{
		if (isset($this->_fileLocation))
			return basename($this->_fileLocation);

		if ($value['error'] == UPLOAD_ERR_NO_FILE)
			return null;

		if ($value['error'] != UPLOAD_ERR_OK)
			throw new Basic_UserinputValue_UploadedFileException('An error `%s` occured while processing the file you uploaded'. array($value['error']));

		$finfo = new finfo(FILEINFO_MIME);

		if (!$finfo)
			throw new Basic_UserinputValue_FileInfoException('Could not open fileinfo-database');

		$mime = $finfo->file($value['tmp_name']);

		if (false !== strpos($mime, ';'))
			$mime = array_shift(explode(';', $mime));

		if (!in_array($mime, $this->_config['options']['mimetypes']))
			throw new Basic_UserinputValue_FileInvalidMimeTypeException('The uploaded file has an invalid MIME type `%s`', array($mime));

		$this->_fileLocation = APPLICATION_PATH .'/'. $this->_config['options']['path'] .'/'. sha1_file($value['tmp_name']) .'.'. array_pop(explode('/', $mime));

		if (file_exists($this->_fileLocation))
			unlink($value['tmp_name']);
		elseif (!move_uploaded_file($value['tmp_name'], $this->_fileLocation))
			throw new Basic_UserinputValue_CouldNotMoveFileException('Could not move the uploaded file to its target path `%s`', array($this->_config->{$name}['options']['path']));

		// We do not need the full path in the database
		return basename($this->_fileLocation);
	}

	protected static function _convertEncodingDeep($value)
	{
		if ('UTF-8' == Basic::$action->encoding)
			return $value;

		return is_array($value) ? array_map(array(self, '_convertEncodingDeep'), $value) : (isset($value) ? mb_convert_encoding($value, Basic::$action->encoding, 'UTF-8') : NULL);
	}
}