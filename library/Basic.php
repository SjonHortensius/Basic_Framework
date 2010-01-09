<?php

class Basic
{
	public static $config;
	public static $log;
	public static $controller;
	public static $userinput;
	public static $template;
	public static $action;	// this will be filled by the Controller

	public static function bootstrap()
	{
		define('APPLICATION_PATH', dirname($_SERVER['SCRIPT_FILENAME']));
		define('FRAMEWORK_PATH', realpath(dirname(__FILE__) .'/../'));

		require_once(FRAMEWORK_PATH .'/library/Basic/Exception.php');
		spl_autoload_register(array('Basic_Exception', 'autoCreate'));

		require_once(FRAMEWORK_PATH .'/library/Basic/Loader.php');
		spl_autoload_register(array('Basic_Loader', 'autoLoad'));

		set_error_handler(array('Basic_Exception', 'errorToException'), ini_get('error_reporting'));

		self::$config = new Basic_Config;
		self::$log = new Basic_Log;
		self::$controller = new Basic_Controller;
		self::$userinput = new Basic_Userinput;
		self::$template = new Basic_Template;

		self::checkEnvironment();

		// Start the action
		self::$controller->init();
		self::$controller->run();
		self::$controller->end();
	}

	public static function checkEnvironment()
	{
		if (!is_writable(APPLICATION_PATH .'/cache/'))
			throw new Basic_Environment_NotWritableException('`%s` is not writable', array(APPLICATION_PATH .'/cache/'));
	}
}

// Basic extra PHP construct
function ifsetor(&$object, $default = null)
{
	return (isset($object)) ? $object : $default;
}