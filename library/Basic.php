<?php

class Basic
{
	const VERSION = '1.1';

	public static $config;
	public static $log;
	public static $controller;
	public static $userinput;
	public static $template;
	// Instantiated by the Controller
	public static $action;
	public static $database;

	public static function bootstrap()
	{
		define('APPLICATION_PATH', dirname($_SERVER['SCRIPT_FILENAME']));
		define('FRAMEWORK_PATH', realpath(dirname(__FILE__) .'/../'));

		spl_autoload_register(array('Basic', 'autoLoad'));
		spl_autoload_register(array('Basic_Exception', 'autoCreate'));

		set_error_handler(array('Basic_Exception', 'errorToException'), ini_get('error_reporting'));

		ob_start();

		self::checkEnvironment();

		self::$config = self::_getCached('Config');
		self::$log = new Basic_Log;
		self::$userinput = new Basic_Userinput;
		self::$controller = new Basic_Controller;
		self::$template = self::_getCached('Template');

		self::_dispatch();
	}

	private static function _getCached($className)
	{
		$cachePath = APPLICATION_PATH .'/cache/'. $className .'.cache';

		if (!file_exists($cachePath))
		{
			$className = 'Basic_'. $className;
			$object = new $className();

			file_put_contents($cachePath, serialize($object));

			return $object;
		}
		else
			return unserialize(file_get_contents($cachePath));
	}

	private static function _dispatch()
	{
		// Start the action
		try
		{
			self::$controller->init();
			self::$controller->run();
			self::$controller->end();
		}
		catch (Exception $e)
		{
			echo $e;
		}
	}

	public static function checkEnvironment()
	{
		if (!is_writable(APPLICATION_PATH .'/cache/'))
			throw new Basic_Environment_NotWritableException('`%s` is not writable', array(APPLICATION_PATH .'/cache/'));
	}

	public static function autoLoad($className)
	{
		// Do not try to find Exceptions
		if ('Basic_Exception' != $className && 'Exception' == substr($className, -strlen('Exception')))
			return;

		$parts = explode('_', $className);

		if ('Basic' == $parts[0])
			$path = FRAMEWORK_PATH .'/library/'. implode('/', $parts) .'.php';
		else
			$path = APPLICATION_PATH .'/library/'. implode('/', $parts) .'.php';

		if (file_exists($path))
			include($path);
	}

	public static function debug()
	{
		if (Basic::$config->PRODUCTION_MODE)
			throw new Basic_Exception('Unexpected Basic::debug statement');

		header('Content-type: text/html');
		ob_end_clean();

		echo '<pre>';

		foreach (func_get_args() as $argument)
			var_dump($argument);

		die;
	}
}

// Basic additional function
function ifsetor(&$object, $default = null)
{
	return (isset($object)) ? $object : $default;
}

function array_has_keys(array $array)
{
	$i = 0;
	foreach ($array as $k => $v)
		if ($k !== $i++)
			return TRUE;

	return FALSE;
}
