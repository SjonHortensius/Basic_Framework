<?php

class Basic_UserinputValue
{
	protected $_name;
	protected $_fileLocation;
	protected $_forceValue;

	// To be validated these need to be protected
	protected $_valueType = 'scalar';
	protected $_inputType = 'text';
	protected $_source = ['superglobal' => 'POST'];
	protected $_default;
	protected $_description;
	protected $_regexp;
	protected $_values;
	protected $_required = false;
	protected $_preCallback;
	protected $_postCallback;
	protected $_preReplace = [];
	protected $_postReplace = [];
	protected $_mimeTypes = [];
	protected $_options = [
		'minLength' => 0,
		'maxLength' => 0,
		'minValue' => 0,
		'maxValue' => 0,
	];

	public function __construct(string $name, array $config)
	{
		$this->_name = $name;
		$this->_source['key'] = $name;

		foreach ($config as $key => $value)
			$this->__set($key, $value);
	}

	public function getValue()
	{
		if (isset($this->_forceValue))
			return $this->_forceValue;

		if (!$this->isPresent())
		{
			// ignore _default here; if it would suffice _required shouldn't be set
			if ($this->_required)
				throw new Basic_UserinputValue_RequiredValueNotSetException('Required value not present');

			return $this->_default;
		}

		$value = str_replace(["\r\n", "\r"], "\n", $GLOBALS['_'. $this->_source['superglobal'] ][ $this->_source['key'] ]);

		// Firefox can only POST XMLHTTPRequests as UTF-8, see http://www.w3.org/TR/XMLHttpRequest/#send
		if (isset($_SERVER['CONTENT_TYPE']) && strtoupper(array_pop(explode('; charset=', $_SERVER['CONTENT_TYPE']))) == 'UTF-8')
			$value = self::_convertEncodingDeep($value);

		if ('file' == $this->_inputType)
			$value = call_user_func([$this, '_handleFile'], $value, $this);

		if (isset($this->_preCallback))
			$value = call_user_func($this->_preCallback, $value, $this);

		foreach ($this->_preReplace as $preg => $replace)
			$value = preg_replace($preg, $replace, $value);

		$this->validate($value);

		foreach ($this->_postReplace as $preg => $replace)
			$value = preg_replace($preg, $replace, $value);

		if (isset($this->_postCallback))
			$value = call_user_func($this->_postCallback, $value, $this);

		return $value;
	}

	public function isValid(): bool
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

	public function isPresent(): bool
	{
		return isset($this->_forceValue) || array_key_exists($this->_source['key'], $GLOBALS['_'. $this->_source['superglobal'] ]);
	}

	public function isGlobal(): bool
	{
		return isset(Basic::$config->Userinput->{$this->_name});
	}

	public function setValue($value, bool $validate = true): void
	{
		if ($validate)
			$this->validate($value);

		$this->_forceValue = $value;
	}

	public function getHtml(): string
	{
		if (!isset($this->_inputType) || 'POST' != $this->_source['superglobal'])
			return '';

		Basic::$log->start();

		$classParts = array_slice(explode('_', get_class(Basic::$action)), 2);
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
		Basic::$template->rawValue = $this->_forceValue ?? ($GLOBALS['_'. $this->_source['superglobal'] ][ $this->_source['key'] ] ?? $this->_default);

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
					throw new Basic_UserinputValue_Configuration_InvalidDefaultException('Invalid default NULL');
				elseif ($value !== null && !$this->validate($value, false))
					throw new Basic_UserinputValue_Configuration_InvalidDefaultException('Invalid default `%s`', [$value]);
			break;

			case 'source':
				$value = (array)$value + $this->_source;

				if (!is_array($GLOBALS[ '_'. $value['superglobal'] ]))
					throw new Basic_UserinputValue_Configuration_InvalidSuperglobalException('Unknown superglobal `%s`', [$value['superglobal']]);
			break;

			case 'values':
				if ($value instanceof Traversable)
					$value = iterator_to_array($value);
				else
					settype($value, 'array');

				if ('POST' == $this->_source['superglobal'] && 'text' == $this->_inputType)
					$this->_inputType = 'select';
			break;

			case 'required':
				if (!is_bool($value))
					throw new Basic_UserinputValue_Configuration_NotBooleanRequiredException('A boolean is expected for `required`');
			break;

			case 'valueType':
				if (!in_array($value, ['scalar', 'integer', 'boolean', 'numeric'], true))
					throw new Basic_UserinputValue_Configuration_InvalidValuetypeException('Invalid valueType `%s`', [$value]);
			break;

			case 'options':
				foreach (array_intersect_key($value, $this->_options) as $_key => $_value)
					if (gettype($_value) != gettype($this->_options[$_key]))
						throw new Basic_UserinputValue_Configuration_InvalidOptionTypeException('Invalid type `%s` for option `%s`', [gettype($value), $_key]);

				$value = $value + $this->_options;
			break;

			case 'preCallback':
			case 'postCallback':
				if (!is_callable($value))
					throw new Basic_UserinputValue_Configuration_InvalidCallbackException('Invalid callback `%s`', [$value[0] .'::'. $value[1]]);
			break;

			default:
				throw new Basic_UserinputValue_Configuration_UnknownPropertyException('Unknown property `%s` in configuration', [$key]);
			break;
		}

		$this->{'_'.$key} = $value;
	}

	public function validate($value, bool $throw = true): bool
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
		catch (Basic_UserinputValue_ValidateException $e)
		{
			if ($throw)
				throw $e;

			return false;
		}
	}

	protected function _validate($value): bool
	{
		$validator = 'is_'. $this->_valueType;
		if ('integer' == $this->_valueType)
		{
			if ($value != (int)$value)
				throw new Basic_UserinputValue_Validate_InvalidValueTypeException('Expected type `%s` but found `%s`', [$this->_valueType, gettype($value)], 404);
		} elseif (!$validator($value))
			throw new Basic_UserinputValue_Validate_InvalidValueTypeException('Expected type `%s` but found `%s`', [$this->_valueType, gettype($value)], 404);

		if (isset($this->_values))
		{
			$values = [];
			foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($this->values)) as $_key => $_value)
				array_push($values, $_key);

			if (!in_array($value, $values)) # not strict for is_numeric and values=array('x')
				throw new Basic_UserinputValue_Validate_ArrayValueException('Unknown value `%s`', [$value], 404);
		}

		if (isset($this->_regexp) && !preg_match($this->_regexp, $value))
			throw new Basic_UserinputValue_Validate_RegexpException('Value `%s` does not match specified regular expression', [$value], 404);

		if (!empty($this->_options['minLength']) && strlen($value) < $this->_options['minLength'])
			throw new Basic_UserinputValue_Validate_MinimumLengthException('Value `%s` is too short', [$value], 404);

		if (!empty($this->_options['maxLength']) && strlen($value) > $this->_options['maxLength'])
			throw new Basic_UserinputValue_Validate_MaximumLengthException('Value `%s` is too long', [$value], 404);

		if (!empty($this->_options['minValue']) && intval($value) < $this->_options['minValue'])
			throw new Basic_UserinputValue_Validate_MinimumValueException('Value `%s` is too low', [$value], 404);

		if (!empty($this->_options['maxValue']) && intval($value) > $this->_options['maxValue'])
			throw new Basic_UserinputValue_Validate_MaximumValueException('Value `%s` is too high', [$value], 404);

		return true;
	}

	// This is a forced preCallback for file-inputs
	protected function _handleFile(array $value): string
	{
		if (isset($this->_fileLocation))
			return basename($this->_fileLocation);

		if ($value['error'] == UPLOAD_ERR_NO_FILE)
			return null;

		if ($value['error'] != UPLOAD_ERR_OK)
			throw new Basic_UserinputValue_UploadedFileException('An error `%s` occured while processing the file you uploaded'. [$value['error']]);

		$finfo = new finfo(FILEINFO_MIME);

		if (!$finfo)
			throw new Basic_UserinputValue_FileInfoException('Could not open fileinfo-database');

		$mime = $finfo->file($value['tmp_name']);

		if (false !== strpos($mime, ';'))
			$mime = array_shift(explode(';', $mime));

		if (!empty($this->options['mimeTypes']) && !in_array($mime, $this->options['mimeTypes']))
			throw new Basic_UserinputValue_FileInvalidMimeTypeException('The uploaded file has an invalid MIME type `%s`', [$mime]);

		$this->_fileLocation = APPLICATION_PATH .'/'. $this->_options['path'] .'/'. sha1_file($value['tmp_name']) .'.'. array_pop(explode('/', $mime));

		if (file_exists($this->_fileLocation))
			unlink($value['tmp_name']);
		elseif (!move_uploaded_file($value['tmp_name'], $this->_fileLocation))
			throw new Basic_UserinputValue_CouldNotMoveFileException('Could not move the uploaded file to its target path `%s`', [$this->_options['path']]);

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