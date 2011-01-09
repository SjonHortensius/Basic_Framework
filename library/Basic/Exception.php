<?php

class Basic_Exception extends Exception
{
	public function __construct($message, $params = NULL, $code = 0, $previous = null)
	{
		if (false !== strpos($message, '%s') && is_array($params))
			$message = vsprintf($message, $params);

		parent::__construct($message, $code, $previous);
	}

	public static function autoCreate($class)
	{
		// Only create Exceptions
		if ('Exception' != substr($class, -strlen('Exception')))
			return;

		// Create a hierarchy of Exceptions: X_Y_AnException extends X_Y_Exception extends X_Exception
		$classParts = explode('_', $class);
		if ('Exception' == array_pop($classParts))
			array_pop($classParts);

		// Did we end up at the top of the hierarchy, then link it to ourself
		if (0 == count($classParts))
			$classParts = array('Basic');

		$parentException = implode('_', $classParts) .'_Exception';

		eval('class '. $class .' extends '. $parentException .' {};');
	}

	public static function errorToException($number, $string, $file, $line)
	{
		if (!Basic::$config->PRODUCTION_MODE)
			throw new Basic_PhpException($string .' in `%s`:%s', array($file, $line));

		// Log this error ourselves, do not execute internal PHP errorhandler
		error_log($string .' in '. $file .' on line '. $line ."\n". Basic_Log::getSimpleTrace());

		throw new Basic_PhpException('An unexpected error has occured, please contact the webmaster');
    }

	public function __toString()
	{
		if (!headers_sent())
			header('Content-Type: '. (isset(Basic::$action->contentType) ? Basic::$action->contentType : 'text/html'), true, 500);

		try {
			if (isset(Basic::$action) && !in_array('header', Basic::$action->templatesShown))
				Basic::$action->showTemplate('header');
		} catch (Exception $e){}

		$variables = array(
			'name' => get_class($this),
			'message' => $this->getMessage(),
			'file' => basename($this->getFile()),
			'line' => $this->getLine(),
		);

		if (!Basic::$config->PRODUCTION_MODE)
		{
			$variables += array(
				'data' => print_r($this->data, TRUE),
				'trace' => $this->getTrace(),
				'trace_string' => $this->getTraceAsString(),
			);
		}

		if (isset(Basic::$action))
		{
			Basic::$action->exception = $variables;

			try
			{
				Basic::$action->showTemplate('exception', TEMPLATE_DONT_STRIP);

				try {
					Basic::$action->end();
				} catch (Exception $e){
					try {
						Basic::$action->showTemplate('footer');
					} catch (Exception $e) {}
				}

				return '';
			} catch (Exception $e) {}
		} elseif (!headers_sent())
			header('Content-type: text/plain');

		// Hide Stack-trace if necessary
		if (Basic::$config->PRODUCTION_MODE)
			return "An error has occured:\n". get_class($this) .': '. $this->getMessage() ."\nthrown from ". $this->getFile() .':'. $this->getLine();
		else
			return parent::__toString();
	}
}

class Basic_PhpException extends Basic_Exception {}