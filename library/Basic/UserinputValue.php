<?php

class Basic_UserinputValue
{
	protected $_name;
	protected $_rawValue;
	protected $_fileLocation;

	// To be validated these need to be protected
	protected $_valueType = 'scalar';
	protected $_inputType = 'text';
	protected $_source = array('superglobal' => 'POST');
	protected $_default;
	protected $_description;
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
			$this->__set($key, $value);

		$source = $GLOBALS['_'. $this->_source['superglobal'] ];
		$this->_rawValue =& $source[ $this->_source['key'] ];
	}

	public function getValue()
	{
		if (!isset($this->_rawValue))
		{
			// ignore _default here; if it would suffice _required shouldn't be set
			if ($this->_required)
				throw new Basic_UserinputValue_RequiredValueNotSetException('Required value not set');

			return $this->_default;
		}

		$value = str_replace(array("\r\n", "\r"), "\n", $this->_rawValue);

		// Firefox can only POST XMLHTTPRequests as UTF-8, see http://www.w3.org/TR/XMLHttpRequest/#send
		if (isset($_SERVER['CONTENT_TYPE']) && strtoupper(array_pop(explode('; charset=', $_SERVER['CONTENT_TYPE']))) == 'UTF-8')
			$value = self::_convertEncodingDeep($value);

		if ('file' == $this->_inputType)
			$value = call_user_func(array($this, '_handleFile'), $value, $this);

		if (isset($this->_options['preCallback']))
			$value = call_user_func($this->_options['preCallback'], $value, $this);

		foreach ($this->_options['preReplace'] as $preg => $replace)
			$value = preg_replace($preg, $replace, $value);

		$this->validate($value, false);

		foreach ($this->_options['postReplace'] as $preg => $replace)
			$value = preg_replace($preg, $replace, $value);

		if (isset($this->_options['postCallback']))
			$value = call_user_func($this->_options['postCallback'], $value, $this);

		return $value;
	}

	public function isValid()
	{
		try
		{
			$this->getValue();
			return true;
		}
		catch (Basic_UserinputValueException $e)
		{
			Basic::$log->write($this->_name .': '. $e->getMessage());
			return false;
		}
	}

	public function isPresent()
	{
		return isset($this->_rawValue);
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
		if (!isset($this->_inputType) || 'POST' != $this->_source['superglobal'])
			return;

		Basic::$log->start();

		$classParts = array_map('ucfirst', explode('_', Basic::$controller->action));
		$paths = $wPaths = [];

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
		Basic::$template->rawValue = $this->_rawValue ?? $this->_default;

		if ($this->isValid())
			Basic::$template->state = 'valid';
		else
			Basic::$template->state = 'invalid';

		Basic::$template->userInputHtml = Basic::$template->showFirstFound($paths, Basic_Template::RETURN_STRING);
		$html = Basic::$template->showFirstFound($wPaths, Basic_Template::RETURN_STRING);

		Basic::$log->end($this->_name);

		return $html;
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
			case 'description':
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
				if (!in_array($value, array('scalar', 'integer', 'boolean', 'numeric'), true))
					throw new Basic_UserinputValue_Configuration_InvalidValuetypeException('Invalid valueType `%s`', array($value));
			break;

			case 'options':
				foreach (array_intersect_key($value, $this->_options) as $_key => $_value)
					if (gettype($_value) != gettype($this->_options[$_key]))
						throw new Basic_UserinputValue_Configuration_InvalidOptionTypeException('Invalid type `%s` for option `%s`', array(gettype($value), $_key));

				$value = $value + $this->_options;
			break;

			default:
				throw new Basic_UserinputValue_Configuration_UnknownPropertyException('Unknown property `%s` in configuration', [$key]);
			break;
		}

		$this->{'_'.$key} = $value;
	}

	public function validate($value, $simple = true)
	{
		try
		{
			if (is_array($value))
			{
				foreach ($value as $_value)
					$this->_validate($_value);
			}
			else
				$this->_validate($value);

			return true;
		}
		catch (Basic_UserinputValue_Validate_Exception $e)
		{
			if ($simple)
				return false;

			throw $e;
		}
	}

	protected function _validate($value)
	{
		$validator = 'is_'. $this->_valueType;
		if ('integer' == $this->_valueType)
		{
			if ($value != (int)$value)
				throw new Basic_UserinputValue_Validate_InvalidValueTypeException('Expected type `%s` but found `%s`', array($this->_valueType, gettype($value)), 404);
		} elseif (!$validator($value))
			throw new Basic_UserinputValue_Validate_InvalidValueTypeException('Expected type `%s` but found `%s`', array($this->_valueType, gettype($value)), 404);

		if (isset($this->_values))
		{
			$values = [];
			foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($this->values)) as $_key => $_value)
				array_push($values, $_key);

			if (!in_array($value, $values)) # not strict for is_numeric and values=array('x')
				throw new Basic_UserinputValue_Validate_ArrayValueException('Unknown value `%s`', array($value), 404);
		}

		if (isset($this->_regexp) && !preg_match($this->_regexp, $value))
			throw new Basic_UserinputValue_Validate_RegexpException('Value `%s` does not match specified regular expression', array($value), 404);

		if (!empty($this->_options['minLength']) && strlen($value) < $this->_options['minLength'])
			throw new Basic_UserinputValue_Validate_MinimumLengthException('Value `%s` is too short', array($value), 404);

		if (!empty($this->_options['maxLength']) && strlen($value) > $this->_options['maxLength'])
			throw new Basic_UserinputValue_Validate_MaximumLengthException('Value `%s` is too long', array($value), 404);

		if (!empty($this->_options['minValue']) && intval($value) < $this->_options['minValue'])
			throw new Basic_UserinputValue_Validate_MinimumValueException('Value `%s` is too low', array($value), 404);

		if (!empty($this->_options['maxValue']) && intval($value) > $this->_options['maxValue'])
			throw new Basic_UserinputValue_Validate_MaximumValueException('Value `%s` is too high', array($value), 404);

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

		return is_array($value) ? array_map([__CLASS__, '_convertEncodingDeep'], $value) : (isset($value) ? mb_convert_encoding($value, Basic::$action->encoding, 'UTF-8') : NULL);
	}
}