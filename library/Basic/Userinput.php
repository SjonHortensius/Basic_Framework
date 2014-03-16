<?php

class Basic_Userinput implements ArrayAccess, IteratorAggregate
{
	protected $_config = array();

	public function __construct()
	{
		foreach (Basic::$config->Userinput as $name => $config)
		{
			$config->source = (array)$config->source;
			$this->_config[$name] = (array)$config;
		}
	}

	public function init()
	{
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
		return false;
	}

	public function __get($name)
	{
		throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));
	}

	public function __set($name, array $config)
	{
		if ('_' == $name[0])
			throw new Basic_Userinput_InvalidNameException('`%s` has an invalid name', array($name));

		$this->$name = new Basic_UserinputValue($name, $config);
	}

	public function __unset($name)
	{
		throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));
	}

	public function getHtml()
	{
		if ('html' != Basic::$template->getExtension())
		{
			$missing = array();
			foreach ($this as $name => $value)
				if (!$value->isValid())
					array_push($missing, $name);

			throw new Basic_Userinput_UnsupportedContentTypeException('ContentType `%s` is not supported, missing inputs: `%s`', array(Basic::$template->getExtension(), implode('`, `', $missing)));
		}

		if (substr($_SERVER['REQUEST_URI'], 0, strlen(Basic::$config->Site->baseUrl)) != Basic::$config->Site->baseUrl)
			throw new Basic_Userinput_IncorrectRequestUrlException('Current URL does not start with baseUrl');

		Basic::$template->formContainsFile = false;
		foreach ($this as $value)
			if ('file' == $value->inputType)
				Basic::$template->formContainsFile = true;

		Basic::$template->formAction = substr($_SERVER['REQUEST_URI'], strlen(Basic::$config->Site->baseUrl));
		Basic::$template->hasBeenSubmitted = ('POST' == $_SERVER['REQUEST_METHOD']);

		$classParts = array_map('ucfirst', explode('_', Basic::$controller->action));
		$paths = array();

		do
			array_push($paths, 'Userinput'. (empty($classParts) ? '' : '/'. implode('/', $classParts)) .'/Form');
		while (null !== array_pop($classParts));

		array_push($paths, FRAMEWORK_PATH .'/templates/Userinput/Form');

		return Basic::$template->showFirstFound($paths, Basic_Template::RETURN_STRING);
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

	public function getIterator()
	{
		return new ArrayIterator($this);
	}
}
