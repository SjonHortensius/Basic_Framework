<?php

class Basic_Exception extends Exception
{
	public function __construct($message, $params = NULL, $code = 0, $previous = null)
	{
		if (false !== strpos($message, '%s') && is_array($params))
			$message = vsprintf($message, $params);

		parent::__construct($message, $code, $previous);
	}

	public static function autoCreate($className)
	{
		// Create a hierarchy of Exceptions: X_Y_AnException extends X_Y_Exception extends X_Exception
		$classParts = explode('_', $className);
		if ('Exception' == array_pop($classParts))
			array_pop($classParts);

		// Did we end up at the top of the hierarchy, then link it to ourself
		if (0 == count($classParts))
			$classParts = array('Basic');

		$parentException = implode('_', $classParts) .'_Exception';

		eval('class '. $className .' extends '. $parentException .' {};');
	}

	public static function errorToException($number, $string, $file, $line)
	{
		if (!Basic::$config->PRODUCTION_MODE)
			throw new Basic_PhpException($string .' in `%s`:%s', array($file, $line));

		// Log this error ourselves, do not execute internal PHP errorhandler
		error_log($string .' in '. $file .' on line '. $line);
		return true;
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

			if (isset($_GET['debug']))
			{
				$data = array_merge($GLOBALS); // Force dereferencing
				unset($data['GLOBALS'], $data['HTTP_POST_VARS'], $data['HTTP_GET_VARS'], $data['HTTP_SERVER_VARS'], $data['HTTP_COOKIE_VARS'], $data['HTTP_ENV_VARS'], $data['HTTP_POST_FILES']);
				var_dump($data);
			}
		}

		if (isset(Basic::$action))
		{
			Basic::$action->exception = $variables;

			try
			{
				Basic::$action->showTemplate('exception', TEMPLATE_DONT_STRIP);

				try {
					Basic::$action->showTemplate('footer');
				} catch (Exception $e){}

				return '';
			} catch (Exception $e) {}
		}

		return parent::__toString();
	}
}

class Basic_PhpException extends Basic_Exception {}