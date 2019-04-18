<?php

class Basic
{
	const VERSION = '1.4';

	/** @var Basic_Config */
	public static $config;
	/** @var Basic_Log */
	public static $log;
	/** @var Basic_Controller */
	public static $controller;
	/** @var Basic_Userinput */
	public static $userinput;
	/** @var Basic_Template */
	public static $template;
	/** @var Basic_Memcache */
	public static $cache;
	/** @var Basic_Database */
	public static $database;
	/** @var Basic_Action */
	public static $action;
	protected static $_classes;

	/**
	 * Setup the entire framework and start the init/run/end sequence
	 */
	public static function bootstrap()
	{
		define('APPLICATION_PATH', realpath(dirname($_SERVER['SCRIPT_FILENAME']). '/../'));
		define('FRAMEWORK_PATH',   realpath(__DIR__ .'/../'));

		error_reporting(E_ALL & ~E_NOTICE);

		spl_autoload_register(['Basic', '_load']);
		spl_autoload_register(['Basic_Exception', 'autoCreate']);

		self::_checkEnvironment();

		// Start with a default config for bootstrapping
		self::$config = (object)['PRODUCTION_MODE' => true];

		self::$log =    new Basic_Log;
		self::$cache =  new Basic_Memcache;
		self::$config = new Basic_Config;

		if (Basic::$config->PRODUCTION_MODE)
		{
			// Replace simple loader by instance that caches existence of files
			spl_autoload_unregister(['Basic', '_load']);
			spl_autoload_register(['Basic', '_loadCached'], true, true);
		} else
			self::$cache->flush();

		self::$userinput =  new Basic_Userinput;
		self::$controller = new Basic_Controller;
		self::$template =   new Basic_Template;

		set_error_handler(['Basic_PhpException', 'fromError'], error_reporting());

		if (isset(self::$config->Database))
		{
			$failCount = 0;
			do
			{
				try
				{
					self::$database = new Basic_Database;
				}
				catch (PDOException $e)
				{
					if (++$failCount >= 3)
						throw $e;
					else
						sleep(1);
				}
			} while (!isset(self::$database));
		}

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
			if (!isset(Basic::$action) && !headers_sent())
				header('Content-type: text/plain');

			echo $e;
		}
	}

	protected static function _checkEnvironment()
	{
		if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 70300)
			throw new Basic_Environment_PhpVersionTooOldException('Your PHP version `%s` is too old', [phpversion()]);

		if (!is_writable(APPLICATION_PATH .'/cache/'))
			throw new Basic_Environment_NotWritableException('`%s` is not writable', [APPLICATION_PATH .'/cache/']);

		if (get_magic_quotes_gpc())
			throw new Basic_Environment_DisableMagicQuotesException('Please disable `magic_quotes_gpc` in your configuration');

		if (!ini_get('short_open_tag') || ini_get('asp_tags'))
			throw new Basic_Environment_MissingSettingException('Setting `short_open_tags` is required, `asp_tags` is disallowed');
	}

	protected static function _loadCached($class)
	{
		if (isset(self::$_classes[$class]))
			require(self::$_classes[$class]);

		if (isset(self::$_classes))
			return;

		self::$_classes = Basic::$cache->get(self::class .':classes:'. dechex(filemtime(APPLICATION_PATH .'/library')), function(){
			$classes = [];

			foreach ([FRAMEWORK_PATH .'/library/', APPLICATION_PATH .'/library/'] as $base)
				foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $path => $entry)
					$classes[ str_replace('/', '_', substr($path, strlen($base), -strlen('.php'))) ] = $path;

			return $classes;
		});

		self::_loadCached($class);
	}

	protected static function _load($class)
	{
		$parts = explode('_', $class);

		if ('Basic' == $parts[0])
			$path = FRAMEWORK_PATH .'/library/'. implode('/', $parts) .'.php';
		else
			$path = APPLICATION_PATH .'/library/'. implode('/', $parts) .'.php';

		if (file_exists($path))
			require($path);
	}

	public static function debug(...$args)
	{
		if (Basic::$config->PRODUCTION_MODE)
			throw new Basic_Exception('Unexpected Basic::debug statement');

		if (!headers_sent())
			header('Content-type: text/html');
		ob_end_clean();

		echo '<h1>'. Basic_Log::getSimpleTrace() .'</h1>';
		echo '<pre>';

		foreach ($args as $argument)
		{
			echo '<hr/>';

			if (is_object($argument) || is_array($argument))
				print htmlspecialchars(print_r($argument, true));
			else
				var_dump($argument);
		}

		echo '<hr style="clear:both;" /><fieldset><legend>Statistics | <b>'.number_format(1000*Basic::$log->getStatistics()['time'], 2).' ms</b></legend>'. Basic::$log->getTimers() .'</fieldset>';
		echo '<fieldset class="log"><legend>Logs</legend><pre>'. Basic::$log->getLogs() .'</pre></fieldset>';

		die;
	}

	// `realpath` alternative with support for relative paths, but no symlink resolving
	public static function resolvePath($path)
	{
		$path = str_replace('/./', '/', $path);

		$_path = [];
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