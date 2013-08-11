<?php

class Basic_Exception extends Exception
{
	public function __construct($message, $params = array(), $code = 0, Exception $cause = null)
	{
		if (!empty($params))
			$message = vsprintf($message, $params);

		parent::__construct($message, $code, $cause);
	}

	public static function autoCreate($class)
	{
		// Only create Exceptions
		if ('Exception' != substr($class, -strlen('Exception')))
			return;

		$parents = explode('_', $class);

		// Create a hierarchy of Exceptions: X_Y_AnException extends X_Y_Exception extends X_Exception
		if ('Exception' == array_pop($parents))
			array_pop($parents);

		// Did we end up at the top of the hierarchy, then link it to ourself
		if (empty($parents) || ($parents == array('Basic')))
		{
			if (isset(Basic::$config->APPLICATION_NAME) && class_exists(Basic::$config->APPLICATION_NAME .'_Exception'))
				$parents = array(Basic::$config->APPLICATION_NAME);
			else
				$parents = array('Basic');
		}

		$parentException = implode('_', $parents) .'_Exception';

		eval('class '. $class .' extends '. $parentException .' {};');
	}

	public static function errorToException($number, $string, $file, $line)
	{
		if (!Basic::$config->PRODUCTION_MODE)
			throw new Basic_PhpException($string .' in `%s`:%s', array($file, $line));

		// Log this error ourselves, do not execute internal PHP errorhandler
		if (ini_get('error_reporting') > 0)
			error_log($string .' in '. $file .' on line '. $line ."\n". Basic_Log::getSimpleTrace());

		throw new Basic_PhpException('An unexpected error has occured, please contact the webmaster');
	}

	public function __toString()
	{
		if (!isset(Basic::$action))
		{
			if (!headers_sent())
				header('Content-type: text/plain');

			return parent::__toString();
		}

		if (!headers_sent())
			header('Content-Type: '. (isset(Basic::$action->contentType) ? Basic::$action->contentType : 'text/html'), true, 500);

		try
		{
			if (!in_array('header', Basic::$action->templatesShown))
				Basic::$action->showTemplate('header');
		}
		catch (Exception $e)
		{}

		if (Basic::$config->PRODUCTION_MODE && isset(Basic::$config->Site->exceptionMail) &&
			(!isset(Basic::$config->Site->exceptionMailBlacklist) || !in_array($this->name, Basic::$config->Site->exceptionMailBlacklist)))
		{
			mail(Basic::$config->Site->exceptionMail, 'An exception has occured @ '. Basic::$controller->action .': '. $_SERVER['REQUEST_URI'], print_r(
				array(
					'request' => $_REQUEST,
					'userinput' => Basic::$userinput->asArray(),
					'name' => $this->name,
					'message' => $this->message,
					'trace' => $this->getTraceAsString(),
					'cause' => $this->getPrevious(),
				), true
			));
		}

		Basic::$template->exception = $this;

		try
		{
			Basic::$action->showTemplate('exception', TEMPLATE_DONT_STRIP);
		}
		catch (Exception $e)
		{
			// Hide details if necessary
			if (Basic::$config->PRODUCTION_MODE)
				return "An error has occured:\n". get_class($this) .': '. $this->getMessage() ."\nthrown from ". $this->getFile() .':'. $this->getLine();
			else
				return parent::__toString();
		}

		try
		{
			Basic::$action->end();
		}
		catch (Exception $e)
		{}

		try
		{
			if (!in_array('footer', Basic::$action->templatesShown))
				Basic::$action->showTemplate('footer');
		}
		catch (Exception $e)
		{}

		return '';
	}

	public function __get($variable)
	{
		switch ($variable)
		{
			default:
				// For protected properties
				if (isset($this->$variable))
					return $this->$variable;
			break;

			case 'trace':
				if (!Basic::$config->PRODUCTION_MODE)
					return $this->getTraceAsString();
			break;

			case 'name':
				return get_class($this);
			break;

			case 'cause':
				if (!Basic::$config->PRODUCTION_MODE)
					return $this->getPrevious();
			break;


		}
	}
}

class Basic_PhpException extends Basic_Exception {}