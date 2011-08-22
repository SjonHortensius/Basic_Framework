<?php

class Basic_Userinput implements ArrayAccess, Iterator
{
	protected $_config = array();
	// we cannot directly assign values to $this since we would be unable to intercept __isset calls (which requires a lot of code updates)
	protected $_values = array();
	protected $_actionValues; // For Iterator

	public function __construct()
	{
		// Cast to array
		foreach (Basic::$config->Userinput as $name => $config)
		{
			$config->source = (array)$config->source;
			$this->_config[$name] = (array)$config;
		}
	}

	public function init()
	{
		$this->_values = array();

		foreach ($this->_config as $name => $config)
			$this->$name = $config;
	}

	public function run()
	{
		foreach (Basic::$action->getUserinputConfig() as $name => $config)
			$this->$name = $config;
	}

	public function isValid()
	{
		foreach ($this as $value)
			if ('POST' == $value->source['superglobal'] && 'POST' != $_SERVER['REQUEST_METHOD'] || !$value->isValid())
				return false;

		return true;
	}

	public function __isset($name)
	{
		return isset($this->_values[ $name ]);
	}

	public function __get($name)
	{
		if (!isset($this->_values[ $name ]))
			throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));

		return $this->_values[ $name ];
	}

	public function __set($name, array $config)
	{
		if ('_' == $name[0])
			throw new Basic_Userinput_InvalidNameException('`%s` has an invalid name', array($name));

		if (!isset($config['source']['action']))
			$config['source']['action'] = array(Basic::$controller->action);

		$this->_values[ $name ] = new Basic_UserinputValue($name, $config);
	}

	public function __unset($name)
	{
		if (!isset($this->_values[ $name ]))
			throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));

		unset($this->_values[ $name ]);
	}

	protected function _getFormData()
	{
		$data = array(
			'method' => 'post',
			'action' => $_SERVER['REQUEST_URI'],
			'inputs' => array(),
			'submitted' => ('POST' == $_SERVER['REQUEST_METHOD']),
		);

		foreach ($this as $name => $value)
		{
			if (!isset($value->inputType))
				continue;

			$input = array_merge($value->getConfig(), $value->getFormData());

			// Determine the state of the input
			if (!$value->isPresent(false) || ($input['validates'] || !$input['required']))
				$input['state'] = 'valid';
			else
				$input['state'] = 'invalid';

			// Special 'hack' for showing selects without keys
			if (!empty($value->values) && in_array('valuesToKeys', $value->options, true))
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
		{
			$missing = array();
			foreach ($this as $name => $value)
				if (!$value->isValid())
					array_push($missing, $name);

			throw new Basic_Userinput_UnsupportedContentTypeException('ContentType `%s` is not supported, missing input: `%s`', array(Basic::$template->getExtension(), implode('`, `', $missing)));
		}

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
		foreach ($this as $name => $value)
			if ($addGlobals || !$value->isGlobal())
				$output[$name] = $value->getValue();

		return $output;
	}

	// Accessing the Userinput as array will act as shortcut to the value
	public function offsetExists($name){		return $this->$name->isPresent();			}
	public function offsetGet($name){			return $this->$name->getValue();			}
	public function offsetSet($name, $value){	throw new Basic_NotSupportedException('');	}
	public function offsetUnset($name){			throw new Basic_NotSupportedException('');	}

    public function rewind()
    {
		$this->_actionValues = array();

		foreach ($this->_values as $name => $value)
			if ($value->isGlobal() || in_array(Basic::$controller->action, $value->source['action']))
				$this->_actionValues[$name] = $value;

		return reset($this->_actionValues);
    }

    public function current(){	return current($this->_actionValues);	}
    public function key(){		return key($this->_actionValues);		}
    public function next(){		next($this->_actionValues);				}
    public function valid(){	return false !== $this->current();		}
}