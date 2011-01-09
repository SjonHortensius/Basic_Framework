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

	protected static function _getCached($class)
	{
		$cachePath = APPLICATION_PATH .'/cache/'. $class .'.cache';

		if (!file_exists($cachePath))
		{
			$class = 'Basic_'. $class;
			$object = new $class();

			file_put_contents($cachePath, serialize($object));

			return $object;
		}
		else
		{
			try
			{
				return unserialize(file_get_contents($cachePath));
			}
			catch (Basic_StaleCacheException $e)
			{
				try
				{
					unlink($cachePath);
				}
				catch (Basic_PhpException $e)
				{
					//ignore
				}

				return self::_getCached($class);
			}
		}
	}

	protected static function _dispatch()
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
			if (!headers_sent())
				header('Content-type: text/plain');

			echo $e;
		}
	}

	public static function checkEnvironment()
	{
		if (!is_writable(APPLICATION_PATH .'/cache/'))
			throw new Basic_Environment_NotWritableException('`%s` is not writable', array(APPLICATION_PATH .'/cache/'));

		if (get_magic_quotes_gpc())
			throw new Basic_Environment_DisableMagicQuotesException('Please disable `magic_quotes_gpc` in your configuration');
	}

	public static function autoLoad($class)
	{
		$parts = explode('_', $class);

		if ('Basic' == $parts[0])
			$path = FRAMEWORK_PATH .'/library/'. implode('/', $parts) .'.php';
		else
			$path = APPLICATION_PATH .'/library/'. implode('/', $parts) .'.php';

		if (file_exists($path))
			require($path);
	}

	public static function debug()
	{
		if (Basic::$config->PRODUCTION_MODE)
			throw new Basic_Exception('Unexpected Basic::debug statement');

		header('Content-type: text/html');
		ob_end_clean();

		echo '<h5>'. Basic_Log::getSimpleTrace() .'</h5>';
		echo '<pre>';

		foreach (func_get_args() as $argument)
			print_r($argument);

		die;
	}

	// `realpath` alternative with support for relative paths, but no symlink resolving
	public static function resolvePath($path)
	{
		$path = str_replace('/./', '/', $path);

		$_path = array();
		foreach (explode('/', $path) as $part)
		{
			if ('..' == $part)
				array_pop($_path);
			elseif ($part !== '')
				array_push($_path, $part);
		}

		return implode('/', $_path);
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
