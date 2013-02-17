<?php

class Basic
{
	const VERSION = '1.1';

	protected static $_classes;
	public static $config;
	public static $log;
	public static $controller;
	public static $userinput;
	public static $template;
	public static $cache;
	// Instantiated by the Controller
	public static $action;
	public static $database;

	public static function bootstrap()
	{
		define('APPLICATION_PATH', realpath(dirname($_SERVER['SCRIPT_FILENAME']). '/../'));
		define('FRAMEWORK_PATH', realpath(dirname(__FILE__) .'/../'));

		spl_autoload_register(array('Basic', 'load'));
		spl_autoload_register(array('Basic_Exception', 'autoCreate'));

		ob_start();
		umask(0);

		self::checkEnvironment();

		self::$log = new Basic_Log;
		self::$cache = new Basic_Memcache;

		// Replace simple loader by instance that caches existence of files
		if (Basic::$config->PRODUCTION_MODE)
		{
			spl_autoload_unregister(array('Basic', 'load'));
			spl_autoload_register(array('Basic', 'loadCached'), true, true);
		}

		self::$config = new Basic_Config;
		self::$userinput = new Basic_Userinput;
		self::$controller = new Basic_Controller;
		self::$template = new Basic_Template;

		set_error_handler(array('Basic_Exception', 'errorToException'), ini_get('error_reporting'));

		self::_dispatch();
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
		if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50300)
			throw new Basic_Environment_PhpVersionTooOldException('Your PHP version `%s` is older then 5.3', array(phpversion()));

		if (!is_writable(APPLICATION_PATH .'/cache/'))
			throw new Basic_Environment_NotWritableException('`%s` is not writable', array(APPLICATION_PATH .'/cache/'));

		if (get_magic_quotes_gpc())
			throw new Basic_Environment_DisableMagicQuotesException('Please disable `magic_quotes_gpc` in your configuration');
	}

	public static function loadCached($class)
	{
		if (isset(self::$_classes[$class]))
			require(self::$_classes[$class]);

		if (isset(self::$_classes))
			return;

		try
		{
			self::$_classes = Basic::$cache->get('Basic::classes');
		}
		catch (Basic_Memcache_ItemNotFoundException $e)
		{
			self::$_classes = array();

			foreach (array(FRAMEWORK_PATH.'/library/', APPLICATION_PATH.'/library/') as $base)
				foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $path => $entry)
					if ($entry->isFile())
						self::$_classes[ str_replace('/', '_', substr($path, strlen($base), -strlen('.php'))) ] = $path;

			Basic::$cache->set('Basic::classes', self::$_classes, 3600);
		}
	}

	public static function load($class)
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

		echo '<h1>'. Basic_Log::getSimpleTrace() .'</h1>';
		echo '<pre>';

		foreach (func_get_args() as $argument)
		{
			echo '<hr/>';

			if (is_object($argument) || is_array($argument))
				print htmlspecialchars(print_r($argument, true));
			else
				var_dump($argument);
		}

		echo '<hr style="clear:both;" /><fieldset><legend>Statistics | <b>'.round(array_shift(Basic::$log->getStatistics()), 4).'</b></legend>'. Basic::$log->getTimers() .'</fieldset>';
		echo '<fieldset class="log"><legend>Logs</legend><pre>'. Basic::$log->getLogs() .'</pre></fieldset>';

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

		return ('/'==$path[0] ? '/' : '') . implode('/', $_path);
	}
}

// Basic additional function
function ifsetor(&$object, $default = null)
{
	return (isset($object)) ? $object : $default;
}