<?php

class Basic_Userinput implements ArrayAccess
{
	/**
	 * Whether or not the user has supplied valid input for all required inputs
	 *
	 * @return bool
	 */
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
			throw new Basic_Action_UserinputUndefinedException('The specified input `%s` is not configured', [$name]);

		$this->$name = Basic::$action->userinputConfig[$name];
		return $this->$name;
	}

	public function __set($name, array $config)
	{
		if ('_' == $name[0])
			throw new Basic_Userinput_InvalidNameException('`%s` has an invalid name', [$name]);

		$this->$name = new Basic_UserinputValue($name, $config);
	}

	public function __unset($name)
	{
		throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', [$name]);
	}

	/**
	 * Get a complete form that lists all inputs that configured for the current action
	 *
	 * @return string Html of the form
	 */
	public function getHtml(): string
	{
		Basic::$template->formContainsFile = false;
		foreach ($this as $value)
			if ('file' == $value->inputType)
				Basic::$template->formContainsFile = true;

		Basic::$template->formAction = Basic::$action::getRoute();
		Basic::$template->hasBeenSubmitted = ('POST' == $_SERVER['REQUEST_METHOD']);

		$classParts = array_slice(explode('_', get_class(Basic::$action)), 2);
		$paths = [];

		do
			array_push($paths, 'Userinput'. (empty($classParts) ? '' : '/'. implode('/', $classParts)) .'/Form');
		while (null !== array_pop($classParts));

		array_push($paths, FRAMEWORK_PATH .'/templates/Userinput/Form');

		return Basic::$template->showFirstFound($paths, Basic_Template::RETURN_STRING);
	}

	/**
	 * Get a complete form, for all inputs that are configured by the specified action
	 *
	 * @param Basic_Action $action Action to retrieve input configuration from
	 * @param array $userinputDefault Associative array of default values for the form
	 * @return string
	 */
	public static function getHtmlFor(Basic_Action $action, array $userinputDefault = []): string
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

	/**
	 * Get all configured userinput in a simple key => value array
	 *
	 * @param bool $globals Whether or not to include global inputs
	 * @return array
	 */
	public function toArray(bool $globals = true): array
	{
		$data = [];

		foreach ($this as $k => $v)
			/** @var $v Basic_UserinputValue */
			if ($globals || !$v->isGlobal())
				$data[$k] = $v->getValue();

		return $data;
	}
}