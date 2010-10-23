<?php

class Basic_Userinput
{
	private $_config;
	private $_validTypes = array('string', 'integer', 'boolean', 'array', 'numeric');
	private $_validOptions = array(
		'minlength' => 'integer',
		'maxlength' => 'integer',
		'minvalue' => 'integer',
		'maxvalue' => 'integer',
		'pre_replace' => 'array',
		'pre_callback' => 'array',
		'post_replace' => 'array',
		'post_callback' => 'array',
	);
	private $_globalInputs;
	private $_globalInputsValid = false;
	private $_actionInputs;
	private $_actionInputsValid = false;
	private $_details = array();

	public function __construct()
	{
		// Cast to array
		foreach (Basic::$config->Userinput as $name => $config)
		{
			$config->source = (array)$config->source;
			$this->_config->$name = (array)$config;
		}

		if (get_magic_quotes_gpc())
			self::_undoMagicQuotes();
	}

	public function init()
	{
		$this->_checkConfig();

		$this->_globalInputs = array();

		foreach ($this->_config as $name => $config)
			if (null == $config['source']['action'])
				array_push($this->_globalInputs, $name);

		$this->_globalInputsValid = $this->_validateAll($this->_globalInputs);
	}

	public function mergeActionConfig()
	{
		if (count(Basic::$action->userinputConfig) == 0)
			return;

		foreach (Basic::$action->userinputConfig as $name => &$config)
		{
			if (!isset($config['source']['action']))
				$config['source']['action'] = array(Basic::$controller->action);

			$this->_config->$name =& $config;
		}

		$this->_checkConfig();
	}

	public function run()
	{
		$this->_actionInputs = array();

		foreach ($this->_config as $name => $config)
			if (isset($config['source']['action']) && in_array(Basic::$controller->action, $config['source']['action']))
				array_push($this->_actionInputs, $name);

		$this->_actionInputsValid = $this->_validateAll($this->_actionInputs);
	}

	public function allInputValid()
	{
		return $this->_actionInputsValid && $this->_globalInputsValid;
	}

	private static function _undoMagicQuotes()
	{
		$_POST = stripSlashesDeep($_POST);
		$_GET = stripSlashesDeep($_GET);
		$_COOKIE = stripSlashesDeep($_COOKIE);
		$_SERVER = stripSlashesDeep($_SERVER);
	}

	private function _checkConfig()
	{
		$default = array(
			'value_type' => 'string',
			'input_type' => 'text',
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

			if (isset($config['values']) && !isset($config['input_type']))
				$config['input_type'] = 'select';

			$config += $default;

			if (isset($config['source']['action']))
				settype($config['source']['action'], 'array');

			if (isset($config['values']))
				settype($config['values'], 'array');

			settype($config['options'], 'array');
			settype($config['options']['pre_replace'], 'array');
			settype($config['options']['post_replace'], 'array');

			if (!isset($config['source']) || !(isset($config['value_type']) || isset($config['regexp']) || isset($config['values']) || isset($config['callback'])))
				throw new Basic_Userinput_ConfigurationInvalidException('`%s` is missing any one of `source`, `regexp`, `values`, `callback`', array($key));

			if (!isset($GLOBALS[ '_'. $config['source']['superglobal'] ]))
				throw new Basic_Userinput_ConfigurationInvalidSuperglobalException('`%s` is using an unknown superglobal ``', array($key, $config['source']['superglobal']));

			if (!isset($config['source']['key']))
				throw new Basic_Userinput_ConfigurationKeyMissingException('`%s` is missing a source-key', array($key));

			if (isset($config['value_type']) && !in_array($config['value_type'], $this->_validTypes, true))
				throw new Basic_Userinput_ConfigurationValueTypeInvalidException('`%s` is using an invalid value-type `%s`', array($key, $config['value_type']));

			// Check the validity of the options and their types
			foreach(array_intersect_key($config['options'], $this->_validOptions) as $option_key => $option_value)
				if (gettype($option_value) != $this->_validOptions[ $option_key ])
					throw new Basic_Userinput_ConfigurationInvalidOptionTypeException('`%s` is using an invalid option-type `%s` for option `%s`', array($key, gettype($option_value), $option_key));
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
		if (!isset($this->_config->$name))
			return false;

		return (!in_array('required', $this->_config->{$name}['options'], true) || isset($this->$name));
	}

	public function __isset($name)
	{
		$details = $this->getDetails($name);

		return $details['isset'] && $details['validates'];
	}

	public function __get($name)
	{
		$details = $this->getDetails($name);

		return ($details['validates'] ? $details['value'] : NULL);
	}

	public function getConfig($name)
	{
		return ifsetor($this->_config->$name, null);
	}

	public function getDetails($name)
	{
		if (!isset($this->_config->$name))
			throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));

		Basic::$log->start();

		$details = array();
		$config = $this->_config->$name;

		$source = $GLOBALS['_'. $config['source']['superglobal'] ];

		if (array_key_exists($config['source']['key'], $source))
		{
			$value = $source[ $config['source']['key'] ];
			$details['isset'] = true;
			$details['raw_value'] = $value;

			if ('file' == $config['input_type'])
				$value = call_user_func(array($this, '_handleFile'), $value, $name);

			if (isset($config['options']['pre_callback']))
				$value = call_user_func($config['options']['pre_callback'], $value, $name);

			foreach ($config['options']['pre_replace'] as $preg => $replace)
				$value = preg_replace($preg, $replace, $value);

			$details['validates'] = $this->_validate($name, $value);

			if ($details['validates'])
				$value = $this->_clean($value);

			foreach ($config['options']['post_replace'] as $preg => $replace)
				$value = preg_replace($preg, $replace, $value);

			if (isset($config['options']['post_callback']))
				$value = call_user_func($config['options']['post_callback'], $value, $name);

			$details['value'] = $value;
		}
		elseif (isset($config['default']))
		{
			$details['isset'] = false;
			$details['raw_value'] = $config['default'];
			$details['validates'] = $this->_validate($name, $config['default']);
		}
		else
			$details['isset'] = $details['validates'] = false;

		$details['value'] = $this->_clean($details['value']);

		$this->_details[ $name ] = $details;

		Basic::$log->end($name);

		// This is handy for debugging
		foreach ($details as $k => $v)
			$logLine[] = $k .' = '. (is_bool($v) ? ($v ? 'true' : 'false') : $v);
		Basic::$log->write(implode(' | ', $logLine));

		return $this->_details[ $name ];
	}

	private function _clean($value)
	{
		$value = str_replace(array("\r\n", "\r"), "\n", $value);

		// Firefox can only POST XMLHTTPRequests as UTF-8, see http://www.w3.org/TR/XMLHttpRequest/#send
		if (isset($_SERVER['CONTENT_TYPE']) && strtoupper(array_pop(explode('; charset=', $_SERVER['CONTENT_TYPE']))) == 'UTF-8')
			$value = convertEncodingDeep($value);

		return $value;
	}

	private function _validate($name, $value)
	{
		$config = $this->_config->$name;

		switch($config['value_type'])
		{
			case 'string':	if (!is_string($value))			return false;	break;
			case 'array':	if (!is_array($value))			return false;	break;
			case 'numeric':	if (!is_numeric($value))		return false;	break;
			case 'integer':	if (intval($value) != $value)	return false;	break;

			default:
				throw new Basic_Userinput_UnknownValueTypeException('Unknown value-type `%s` for `%s`', array($config['value_type'], $name));

			case null:
			break;
		}

		if (isset($config['values']))
		{
			if (array_has_keys($config['values']))
			{
				$values = array();
				// Array-in-array?
				if (is_array(reset($config['values'])))
						foreach ($config['values'] as $_values)
							$values = array_merge($values, array_keys($_values));
				else
					$values = array_keys($config['values']);

				// Multiple values?
				if (is_array($value))
				{
					foreach ($value as $_value)
						if (!in_array($_value, $values))
							return false;
				}
				elseif (!in_array($value, $values))
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

	private function _getFormData()
	{
		$data = array(
			'method' => 'post',
			'action' => $_SERVER['REQUEST_URI'],
			'inputs' => array(),
			'submitted' => ('POST' == $_SERVER['REQUEST_METHOD']),
		);

		// Process userinputs
		foreach (array_merge($this->_actionInputs, $this->_globalInputs) as $name)
		{
			if (!in_array(strtolower($this->_config->{$name}['source']['superglobal']), array('post', 'files')) || !isset($this->_config->{$name}['input_type']))
				continue;

			$input = array_merge($this->_config->$name, $this->getDetails($name));
			$input['is_required'] = in_array('required', $this->_config->{$name}['options'], true);

			// Determine the state of the input
/*			if (!$this->_details[ $name ]['isset'] && empty($this->_details[ $name ]['value']))
				$input['state'] = 'empty';
			else*/if (!$this->_details[ $name ]['validates'])
				$input['state'] = 'invalid';
			else
				$input['state'] = 'valid';

			// Special 'hack' for showing selects without keys
			if (in_array($this->_config->{$name}['input_type'], array('select', 'radio')) && !array_has_keys($this->_config->{$name}['values']) && !empty($this->_config->{$name}['values']))
				$input['values'] = array_combine($this->_config->{$name}['values'], $this->_config->{$name}['values']);

			// When multiple values may be selected, the name must be updated
			if ('array' == $this->_config->{$name}['value_type'])
				$input['source']['key'] .= '[]';

			// When a file is uploaded, the form.enctype must be changed
			if ('file' == $input['input_type'])
				$data['containsFile'] = true;

			$data['inputs'][ $name ] = $input;
		}

		return $data;
	}

	public function createForm()
	{
		if ('html' != Basic::$template->getExtension())
			throw new Basic_Userinput_UnsupportedContentTypeException('The current contentType `%s` is not supported for forms', array(Basic::$template->getExtension()));

		// Make sure the templateparser can find the data
		Basic::$action->formData = $this->_getFormData();

		$classParts = explode('_', Basic::$controller->action);
		$paths = array();

		do
			array_push($paths, 'Userinput/'. ucfirst(implode('/', $classParts)) .'/Form');
		while (null !== array_pop($classParts));

		array_push($paths, FRAMEWORK_PATH .'/templates/userinput_form');

		Basic::$template->showFirstFound($paths);
	}

	public function asArray($addGlobals = true)
	{
		$inputs = $addGlobals ? array_merge($this->_globalInputs, $this->_actionInputs) : $this->_actionInputs;

		if (empty($inputs))
			return array();

		$output = array();
		foreach ($inputs as $name)
			$output[ $name ] = $this->$name;

		return $output;
	}

	public function setDefault($name, $value)
	{
		if (!isset($this->_config->$name))
			throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));

		if (($value === null && in_array('required', $this->_config->{$name}['options'], true)) || ($value !== null && !$this->_validate($name, $value)))
			throw new Basic_Userinput_InvalidDefaultException('Invalid default value `%s` for `%s`', array($value, $name));

		$this->_config->{$name}['default'] = $value;
	}

	public function setValues($name, $values)
	{
		if (!isset($this->_config->$name))
			throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));

		$this->_config->{$name}['values'] = $values;
	}

	public function setRequired($name, $value = true)
	{
		if (!isset($this->_config->$name))
			throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));

		$idx = array_search('required', $this->_config->{$name}['options'], true);

		if (false === $value && false !== $idx)
			unset($this->_config->{$name}['options'][ $idx ]);
		elseif ($value === true && false === $idx)
			array_push($this->_config->{$name}['options'], 'required');
	}

	// This is a forced pre_callback for file-inputs
	private function _handleFile($value, $name)
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
}

function stripSlashesDeep($value)
{
	return is_array($value) ? array_map('stripSlashesDeep', $value) : (isset($value) ? stripslashes($value) : NULL);
}

function convertEncodingDeep($value)
{
	if ('UTF-8' == Basic::$action->encoding)
		return $value;

	return is_array($value) ? array_map('convertEncodingDeep', $value) : (isset($value) ? mb_convert_encoding($value, Basic::$action->encoding, 'UTF-8') : NULL);
}