<?php

class Basic_Exception extends Exception
{
	public function __construct($message, $params = [], $code = 500, ?Exception $cause = null)
	{
		if (!empty($params))
			$message = vsprintf($message, $params);

		parent::__construct($message, $code, $cause);
	}

	public static function autoCreate(string $class): void
	{
		// Only create Exceptions
		if (!preg_match('~[^_]Exception$~', $class))
			return;

		$parents = explode('_', $class);

		// Create a hierarchy of Exceptions: App_Y_AnException extends App_YException extends App_Exception extends Basic_Exception
		if ('Exception' == array_pop($parents))
			array_pop($parents);

		// Did we end up at the top of the hierarchy, then link it to ourself
		if (empty($parents) || ($parents == ['Basic']))
		{
			if (class_exists(Basic::$config->APPLICATION_NAME .'_Exception'))
				$parents = [Basic::$config->APPLICATION_NAME.'_'];
			else
				$parents = ['Basic_'];
		}

		eval('class '. $class .' extends '. implode('_', $parents) .'Exception {}');
	}

	public function __toString()
	{
		if (!headers_sent())
			http_response_code($this->code);

		if (!isset(Basic::$action))
			return parent::__toString();

		if (!Basic::$template->hasShown('header'))
			Basic::$action->showTemplate('header');

		Basic::$template->exception = $this;

		try
		{
			Basic::$action->showTemplate('exception');
		}
		catch (Exception $e)
		{
			// Hide details if necessary
			if (Basic::$config->PRODUCTION_MODE)
				return "An error has occured:\n". get_class($this) .': '. $this->getMessage() ."\nthrown from ". $this->getFile() .':'. $this->getLine();
			else
				return parent::__toString();
		}

		Basic::$action->end();

		if (!Basic::$template->hasShown('footer'))
			Basic::$action->showTemplate('footer');

		return '';
	}
}