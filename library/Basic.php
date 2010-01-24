<?php

class Basic
{
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

		require_once(FRAMEWORK_PATH .'/library/Basic/Exception.php');
		spl_autoload_register(array('Basic_Exception', 'autoCreate'));
		set_error_handler(array('Basic_Exception', 'errorToException'), ini_get('error_reporting'));

		spl_autoload_register(array('Basic', 'autoLoad'));

		self::$config = new Basic_Config;
		self::$log = new Basic_Log;
		self::$controller = new Basic_Controller;
		self::$userinput = new Basic_Userinput;
		self::$template = new Basic_Template;

		self::checkEnvironment();

		self::_dispatch();
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
		$parts = explode('_', $className);

		if ('Basic' == $parts[0])
			$path = FRAMEWORK_PATH .'/library/'. implode('/', $parts) .'.php';
		else
			$path = APPLICATION_PATH .'/library/'. implode('/', $parts) .'.php';

		try
		{
			include($path);
		} catch (Basic_PhpException $e){}
	}
}

// Basic additional function
function ifsetor(&$object, $default = null)
{
	return (isset($object)) ? $object : $default;
}

function array_has_keys($array)
{
	$i = 0;
	foreach (array_keys($array) as $k)
		if ($k !== $i++)
			return TRUE;

	return FALSE;
}
