<?php

class Basic_Exception extends Exception
{
	public function __construct($message, $params = NULL)
	{
		if (false !== strpos($message, '%s') && is_array($params))
			$message = vsprintf($message, $params);

		parent::__construct($message);
	}

	public static function autoCreate($className)
	{
		if (false === strpos($className, 'Exception'))
			return;

		if (strpos($className, 'Exception') + strlen('Exception') == strlen($className))
			eval('class '. $className .' extends Basic_Exception {};');
	}

	public static function errorToException($number, $string, $file, $line)
	{
//		if ($number ^ E_NOTICE)
			throw new Basic_PhpException($string .' in `%s`:%s', array($file, $line));

	    // Don't execute PHP internal error handler
//		if (Basic::$config->PRODUCTION_MODE)
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