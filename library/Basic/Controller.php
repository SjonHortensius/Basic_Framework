<?php

class Basic_Controller
{
	private $_config;
	public $action;

	public function __construct()
	{
		$this->_config = Basic::$config->Controller;
	}

	public function init()
	{
		$this->_initMultiview();
		$this->_initSession();
		$this->_initDatabase();

		Basic::$userinput->init();

		$this->_initAction(Basic::$userinput->action);
		unset(Basic::$userinput->action);

		Basic::$action->init();
	}

	private function _initMultiview()
	{
		// Quickly escape data, the userinput class will do it more thoroughly
		if (get_magic_quotes_gpc())
			$_SERVER['REQUEST_URI'] = stripslashes($_SERVER['REQUEST_URI']);

		$path = parse_url(rawurldecode($_SERVER['REQUEST_URI']), PHP_URL_PATH);
		$path = substr($path, strlen(Basic::$config->site['baseUrl']));

		$GLOBALS['_MULTIVIEW'] = array_filter(explode('/', $path));
	}

	private function _initSession()
	{
		if (!$this->_config['Sessions']['enabled'])
			return false;

		ini_set('session.use_trans_sid', FALSE);
		ini_set('session.use_cookies', TRUE);
		ini_set('session.use_only_cookies', TRUE);
		ini_set('session.gc_maxlifetime', $this->_config['Sessions']['lifetime']);

		session_set_cookie_params($this->_config['Sessions']['lifetime'], Basic::$config->site['baseUrl']. '/');

		if (isset($this->_config['Sessions']['name']))
			ini_set('session.name', $this->_config['Sessions']['name']);

		session_start();

		if (!isset($_SESSION['hits']))
			$_SESSION['hits'] = 1;
		else
			$_SESSION['hits']++;
	}

	private function _initDatabase()
	{
		if (!$this->_config['Database']['enabled'])
			return false;

		Basic::$database = new Basic_Database;
	}

	private function _initAction($action, $orgAction = null)
	{
		Basic::$log->start();

		$className = Basic::$config->APPLICATION_NAME .'_'. ucfirst($action) .'_Action';
		if (!class_exists($className))
			unset($className);
		else
			$hasTemplate = (FALSE == ($template_file = glob(APPLICATION_PATH .'/templates/'. $action .'.*')) || count($template_file) == 0);

		if (isset($className) || $hasTemplate)
			$this->action = $action;
		elseif ($action != 'error_404')
		{
			if (!headers_sent())
				header('HTTP/1.0 404 Not Found');

			return $this->_initAction('error_404', $action);
		}
		else
			throw new Basic_Engine_InvalidActionException('The specified action `%s` does not exist', array($orgAction));

		if (!isset($className))
		{
			$className = Basic::$config->APPLICATION_NAME .'_Action';

			if (!class_exists($className))
				$className = 'Basic_Action';
		}

		if (!(method_exists($className, 'init') && method_exists($className, 'run') && method_exists($className, 'end')))
			throw new Basic_Engine_MissingMethodsException('The actionclass `%s` is missing required methods', array($className));

		Basic::$action = new $className;

		Basic::$log->end();
	}

	public function run()
	{
		Basic::$userinput->run();

		if (Basic::$userinput->allInputValid())
			echo Basic::$action->run();
		else
			Basic::$userinput->create_form();
	}

	public function end()
	{
//		if ($this->transaction)
//			$this->transaction_rollback();

		Basic::$action->end();
	}

	public function handleLastModified()
	{
		if (headers_sent())
			return false;

		$lastModified = ifsetor(Basic::$action->lastModified, 'now');
		$cacheLength = ifsetor(Basic::$action->cacheLength, Basic::$config->site['defaultCacheLength']);

		if ($cacheLength == 0)
		{
			header('Cache-Control: private');
			header('Pragma: no-cache');

			return true;
		}

		if (!is_integer($lastModified))
			$lastModified = strtotime($lastModified);
		$expireDate = strtotime(gmdate('D, d M Y H:i:s \G\M\T', $lastModified).' +'.$cacheLength);

		header('Cache-Control: public');

		if ($lastModified > 0)
			header('Last-modified: '.gmdate("D, d M Y H:i:s \G\M\T", $lastModified));

		header('Expires: '.gmdate("D, d M Y H:i:s \G\M\T", $expireDate));

		if (!isset($_SERVER['HTTP_IF_MODIFIED_SINCE']))
			return true;

		if (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) < $expireDate)
		{
			header('HTTP/1.1 304 Not Modified');
			die();
		}
	}
}