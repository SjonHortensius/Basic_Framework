<?php

class Basic_Userinput implements ArrayAccess
{
	protected $_config = array();
	// we cannot directly assign values to $this since we would be unable to intercept __isset calls (which requires a lot of code updates)
	protected $_values = array();

	public function __construct()
	{
		// Cast to array
		foreach (Basic::$config->Userinput as $name => $config)
		{
			$config->source = (array)$config->source;
			$this->_config[$name] = (array)$config;
		}

		if (get_magic_quotes_gpc())
			self::_undoMagicQuotes();
	}

	public function init()
	{
		foreach ($this->_config as $name => $config)
			$this->_values[ $name ] = new Basic_UserinputValue($name, $config);
	}

	public function run()
	{
		foreach (Basic::$action->getUserinputConfig() as $name => $config)
		{
			if (!isset($config['source']['action']))
				$config['source']['action'] = array(Basic::$controller->action);

			$this->_values[ $name ] = new Basic_UserinputValue($name, $config);
		}
	}

	public function isValid()
	{
		foreach ($this->_values as $value)
		{
			if ('POST' == $value->source['superglobal'] && 'POST' != $_SERVER['REQUEST_METHOD'] || !$value->isValid())
				return false;
		}

		return true;
	}

	public function __isset($name)
	{
		return $this->$name->isPresent();
	}

	public function __get($name)
	{
		if (!isset($this->_values[ $name ]))
			throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));

		return $this->_values[ $name ];
	}

	public function __set($name, $value)
	{
		throw new Basic_NotSupportedException();
	}

	public function addValue($name, $config)
	{
		$this->_values[ $name ] = new Basic_UserinputValue($name, $config);
	}

	public function removeValue($name)
	{
		unset($this->_values[ $name ]);
	}

	public function getValues()
	{
		return $this->_values;
	}

	protected function _getFormData()
	{
		$data = array(
			'method' => 'post',
			'action' => $_SERVER['REQUEST_URI'],
			'inputs' => array(),
			'submitted' => ('POST' == $_SERVER['REQUEST_METHOD']),
		);

		// Process userinputs
		foreach ($this->_values as $name => $value)
		{
			if (!in_array($value->source['superglobal'], array('POST', 'FILES')) || !isset($value->inputType))
				continue;

			$input = array_merge($value->getConfig(), $value->getFormData());

			// Determine the state of the input
			if (!$value->isPresent(false) || ($input['validates'] || !$input['required']))
				$input['state'] = 'valid';
			else
				$input['state'] = 'invalid';

			// Special 'hack' for showing selects without keys
			if (in_array($value->inputType, array('select', 'radio')) && !array_has_keys($value->values) && !empty($value->values))
				$input['values'] = array_combine($value->values, $value->values);

			// When a file is uploaded, the form.enctype must be changed
			if ('file' == $input['inputType'])
				$data['containsFile'] = true;

			//FIXME: can't we assign to $value here? Would be nice, no getConfig needed plus less data copying
			$data['inputs'][ $name ] = $input;
		}

		if ('POST' == $_SERVER['REQUEST_METHOD'] && empty($data['inputs']))
			throw new Basic_Userinput_CannotCreateFormException('Missing data; cannot create a form');

		return $data;
	}

	public function createForm()
	{
		if ('html' != Basic::$template->getExtension())
			throw new Basic_Userinput_UnsupportedContentTypeException('The current contentType `%s` is not supported for forms', array(Basic::$template->getExtension()));

		// Make sure the templateparser can find the data
		Basic::$template->formData = $this->_getFormData();

		$classParts = array_map('ucfirst', explode('_', Basic::$controller->action));
		$paths = array();

		do
			array_push($paths, 'Userinput/'. implode('/', $classParts) .'/Form');
		while (null !== array_pop($classParts));

		array_push($paths, FRAMEWORK_PATH .'/templates/userinput_form');

		Basic::$template->showFirstFound($paths);
	}

	public function asArray($addGlobals = true)
	{
		$output = array();
		foreach ($this->_values as $name => $value)
			if ($addGlobals || !$value->isGlobal())
				$output[$name] = $value->getValue();

		return $output;
	}

	// Accesing the Userinput as array will act as shortcut to the value
	public function offsetExists($name){		return isset($this->$name);					}
	public function offsetGet($name){			return $this->$name->getValue();			}
	public function offsetSet($name, $value){	throw new Basic_NotSupportedException('');	}
	public function offsetUnset($name){			throw new Basic_NotSupportedException('');	}

	protected static function _undoMagicQuotes()
	{
		$_POST = self::_stripSlashesDeep($_POST);
		$_GET = self::_stripSlashesDeep($_GET);
		$_REQUEST = self::_stripSlashesDeep($_COOKIE);
		$_COOKIE = self::_stripSlashesDeep($_COOKIE);
		$_SERVER = self::_stripSlashesDeep($_SERVER);
	}

	protected static function _stripSlashesDeep($value)
	{
		return is_array($value) ? array_map(array(self, '_stripSlashesDeep'), $value) : (isset($value) ? stripslashes($value) : NULL);
	}
}
