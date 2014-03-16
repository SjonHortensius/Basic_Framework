<?php

class Basic_UserinputValue
{
	protected $_name;
	protected $_fileLocation;

	// To be validated these need to be protected
	protected $_valueType = 'scalar';
	protected $_inputType = 'text';
	protected $_source = array('superglobal' => 'POST');
	protected $_default;
	protected $_regexp;
	protected $_values;
	protected $_required = false;
	protected $_options = array(
		'minLength' => 0,
		'maxLength' => 0,
		'minValue' => 0,
		'maxValue' => 0,
		'preReplace' => array(),
		'postReplace' => array(),
		'mimeTypes' => array(),
	);

	public function __construct($name, array $config)
	{
		$this->_name = $name;
		$this->_source['key'] = $name;

		foreach ($config as $key => $value)
			$this->$key = $value;
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

			if (!$validate)
				return true;
		}
		catch (Basic_UserinputValue_NotPresentException $e)
		{
			return false;
		}

		try
		{
			$this->getValue(false);
		}
		catch (Basic_UserinputValue_Validate_Exception $e)
		{
			return false;
		}

		return true;
	}

	public function getRawValue()
	{
		$source = $GLOBALS['_'. $this->_source['superglobal'] ];

		if (!array_key_exists($this->_source['key'], $source))
			throw new Basic_UserinputValue_NotPresentException('This value is not present');

		return $source[ $this->_source['key'] ];
	}

	public function getValue($simple = true)
	{
		try
		{
			$value = $this->getRawValue();
		}
		catch (Basic_UserinputValue_NotPresentException $e)
		{
			$value = $this->_default;
			$validates = $this->validate($value, $simple);

			return $validates ? $value : null;
		}

		$value = str_replace(array("\r\n", "\r"), "\n", $value);

		// Firefox can only POST XMLHTTPRequests as UTF-8, see http://www.w3.org/TR/XMLHttpRequest/#send
		if (isset($_SERVER['CONTENT_TYPE']) && strtoupper(array_pop(explode('; charset=', $_SERVER['CONTENT_TYPE']))) == 'UTF-8')
			$value = self::_convertEncodingDeep($value);

		if ('file' == $this->_inputType)
			$value = call_user_func(array($this, '_handleFile'), $value, $this);

		if (isset($this->_options['preCallback']))
			$value = call_user_func($this->_options['preCallback'], $value, $this);

		foreach ($this->_options['preReplace'] as $preg => $replace)
			$value = preg_replace($preg, $replace, $value);

		$validates = $this->validate($value, $simple);

		foreach ($this->_options['postReplace'] as $preg => $replace)
			$value = preg_replace($preg, $replace, $value);

		if (isset($this->_options['postCallback']))
			$value = call_user_func($this->_options['postCallback'], $value, $this);

		return $validates ? $value : null;
	}

	public function isRequired()
	{
		return $this->_required;
	}

	public function isGlobal()
	{
		return isset(Basic::$config->Userinput->{$this->_name});
	}

	public function getHtml()
	{
		if (!isset($this->_inputType))
			return;

		Basic::$log->start();

		$classParts = array_map('ucfirst', explode('_', Basic::$controller->action));
		$paths = $wPaths = array();

		do
		{
			$base = empty($classParts) ? '' : '/'. implode('/', $classParts);

			array_push($paths, 'Userinput'. $base .'/Name/'. $this->_name);
			array_push($paths, 'Userinput'. $base .'/Type/'. $this->_inputType);
			array_push($paths, 'Userinput'. $base .'/Input');

			array_push($wPaths, 'Userinput'. $base .'/Wrapper');
		}
		while (null !== array_pop($classParts));

		Basic::$template->input = $this;

		try
		{
			Basic::$template->rawValue = $this->getRawValue();
		}
		catch (Basic_UserinputValue_NotPresentException $e)
		{
			Basic::$template->rawValue = $this->_default;
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

		if (!$this->isPresent(false) || ($validates || !$this->_required))
			Basic::$template->state = 'valid';
		else
			Basic::$template->state = 'invalid';

		Basic::$template->userInputHtml = Basic::$template->showFirstFound($paths, Basic_Template::RETURN_STRING);
		$html = Basic::$template->showFirstFound($wPaths, Basic_Template::RETURN_STRING);

		Basic::$log->end($this->_name);

		return $html;
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
		if (!property_exists($this, '_'.$key))
			return null;

		$value = $this->{'_'.$key};

		if ('values' == $key && isset($value) && in_array('valuesToKeys', $this->options, true))
			$value = array_combine($value, $value);

		return $value;
	}

	public function __isset($key)
	{
		return null !== $this->{'_'.$key};
	}

	public function __set($key, $value)
	{
		switch ($key)
		{
			case 'regexp':
			case 'inputType':
				// no validation
			break;

			case 'default':
				if ($value === null && $this->_required)
					throw new Basic_UserinputValue_Configuration_InvalidDefaultException('Invalid default `%s`', array('NULL'));
				elseif ($value !== null && !$this->validate($value))
					throw new Basic_UserinputValue_Configuration_InvalidDefaultException('Invalid default `%s`', array($value));
			break;

			case 'source':
				$value = (array)$value + $this->_source;

				if (!is_array($GLOBALS[ '_'. $value['superglobal'] ]))
					throw new Basic_UserinputValue_Configuration_InvalidSuperglobalException('Unknown superglobal `%s`', array($value['superglobal']));
			break;

			case 'values':
				settype($value, 'array');

				if ('POST' == $this->_source['superglobal'] && 'text' == $this->_inputType)
					$this->_inputType = 'select';
			break;

			case 'required':
				if (!is_bool($value))
					throw new Basic_UserinputValue_Configuration_NotBooleanRequiredException('A boolean is expected for `required`');
			break;

			case 'valueType':
				if (!in_array($value, array('scalar', 'integer', 'boolean', 'array', 'numeric'), true))
					throw new Basic_UserinputValue_Configuration_InvalidValuetypeException('Invalid value-type `%s`', array($value));
			break;

			case 'options':
				foreach (array_intersect_key($value, $this->_options) as $_key => $_value)
					if (gettype($_value) != gettype($this->_options[$_key]))
						throw new Basic_UserinputValue_Configuration_InvalidOptionTypeException('Invalid type `%s` for option `%s`', array(gettype($value), $_key));
			break;

			default:
				throw new Exception;
			break;
		}

		$this->{'_'.$key} = $value;
	}

	protected function _validate($value)
	{
		if (is_array($value))
		{
			foreach ($value as $_value)
				$this->_validate($_value);

			return;
		}

		$validator = 'is_'. $this->_valueType;
		if ('integer' == $this->_valueType)
		{
			if ($value != (int)$value)
				throw new Basic_UserinputValue_Validate_InvalidValueTypeException('Expected type `%s` but found `%s`', array($this->_valueType, gettype($value)));
		} elseif (!$validator($value))
			throw new Basic_UserinputValue_Validate_InvalidValueTypeException('Expected type `%s` but found `%s`', array($this->_valueType, gettype($value)));

		if (isset($this->_values))
		{
			$values = array();
			foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($this->_values)) as $_key => $_value)
				array_push($values, $_key);

			if (!in_array($value, $values)) # not strict for is_numeric and values=array('x')
				throw new Basic_UserinputValue_Validate_ArrayValueException('Unknown value `%s`', array($value));
		}

		if (isset($this->_regexp) && !preg_match($this->_regexp, $value))
			throw new Basic_UserinputValue_Validate_RegexpException('Value `%s` does not match specified regular expression', array($value));

		if (!empty($this->_options['minLength']) && mb_strlen($value) < $this->_options['minLength'])
			throw new Basic_UserinputValue_Validate_MinimumLengthException('Value is too short `%s`', array($value));

		if (!empty($this->_options['maxLength']) && mb_strlen($value) > $this->_options['maxLength'])
			throw new Basic_UserinputValue_Validate_MaximumLengthException('Value is too long `%s`', array($value));

		if (!empty($this->_options['minValue']) && intval($value) < $this->_options['minValue'])
			throw new Basic_UserinputValue_Validate_MinimumValueException('Value is too low `%s`', array($value));

		if (!empty($this->_options['maxValue']) && intval($value) > $this->_options['maxValue'])
			throw new Basic_UserinputValue_Validate_MaximumValueException('Value is too high `%s`', array($value));

		return true;
	}

	// This is a forced preCallback for file-inputs
	protected function _handleFile($value)
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

		if (!empty($this->options['mimeTypes']) && !in_array($mime, $this->options['mimeTypes']))
			throw new Basic_UserinputValue_FileInvalidMimeTypeException('The uploaded file has an invalid MIME type `%s`', array($mime));

		$this->_fileLocation = APPLICATION_PATH .'/'. $this->_options['path'] .'/'. sha1_file($value['tmp_name']) .'.'. array_pop(explode('/', $mime));

		if (file_exists($this->_fileLocation))
			unlink($value['tmp_name']);
		elseif (!move_uploaded_file($value['tmp_name'], $this->_fileLocation))
			throw new Basic_UserinputValue_CouldNotMoveFileException('Could not move the uploaded file to its target path `%s`', array($this->_options['path']));

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