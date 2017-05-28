<?php

class Basic_Userinput implements ArrayAccess
{
	public function isValid()
	{
		foreach ($this as $value)
			if (!$value->isValid())
				return false;

		return true;
	}

	public function __isset($name)
	{
		return false;
	}

	public function __get($name): Basic_UserinputValue
	{
		if (!isset(Basic::$action->userinputConfig[$name]))
			throw new Basic_Action_UserinputUndefinedException('The specified input `%s` is not configured', array($name));

		$this->$name = Basic::$action->userinputConfig[$name];
		return $this->$name;
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
		if (substr($_SERVER['REQUEST_URI'], 0, strlen(Basic::$config->Site->baseUrl)) != Basic::$config->Site->baseUrl)
			throw new Basic_Userinput_IncorrectRequestUrlException('Current URL does not start with baseUrl');

		Basic::$template->formContainsFile = false;
		foreach ($this as $value)
			if ('file' == $value->inputType)
				Basic::$template->formContainsFile = true;

		//FIXME introduce Action::route instead of guessing it here? Also usefull for $actionPath below
		Basic::$template->formAction = substr($_SERVER['REQUEST_URI'], strlen(Basic::$config->Site->baseUrl));
		Basic::$template->hasBeenSubmitted = ('POST' == $_SERVER['REQUEST_METHOD']);

		//FIXME use class-hierarchy instead of action? Eg. [Update, Add]
		$classParts = explode('_', ucwords(Basic::$userinput['action'], '_'));
		$paths = [];

		do
			array_push($paths, 'Userinput'. (empty($classParts) ? '' : '/'. implode('/', $classParts)) .'/Form');
		while (null !== array_pop($classParts));

		array_push($paths, FRAMEWORK_PATH .'/templates/Userinput/Form');

		return Basic::$template->showFirstFound($paths, Basic_Template::RETURN_STRING);
	}

	public static function getHtmlFor(Basic_Action $action, $actionPath = null, array $userinputDefault): string
	{
		$userinput = new self;

		foreach (Basic::$config->Userinput as $name => $config)
			$userinput->$name = (array)$config;

		foreach ($action->userinputConfig as $name => $config)
			if (!isset($userinput->$name))
				$userinput->$name = $config;

		foreach ($userinputDefault as $name => $default)
			$userinput->$name->default = $default;

		// Templates use: foreach (Basic::$userinput as $this->input)
		$org = Basic::$userinput;
		// Help Userinput set formAction correctly and allow extra vars through $action
		$orgAction = Basic::$action;
		// Userinput&Value::getHtml use action to determine list of paths
		$userinput->action->setValue($actionPath??strtolower(array_pop(explode('_', get_class($action)))));

		try
		{
			Basic::$action = $action;
			Basic::$userinput = $userinput;

			return $userinput->getHtml();
		}
		finally
		{
			Basic::$userinput = $org;
			Basic::$action = $orgAction;
		}
	}

	// Accessing the Userinput as array will act as shortcut to the value
	public function offsetExists($name){		return $this->$name->isPresent();			}
	public function offsetGet($name){			return $this->$name->getValue();			}
	public function offsetSet($name, $value){	throw new Basic_NotSupportedException('');	}
	public function offsetUnset($name){			throw new Basic_NotSupportedException('');	}

	public function toArray($globals = true): array
	{
		$data = [];

		foreach (get_object_vars($this) as $k => $v)
			if ($globals || !$v->isGlobal())
				$data[$k] = $v->getValue();

		return $data;
	}
}