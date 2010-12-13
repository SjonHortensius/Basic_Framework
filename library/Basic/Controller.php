<?php

class Basic_Controller
{
	public $action;

	public function init()
	{
		$this->_initMultiview();
		$this->_initSession();
		$this->_initDatabase();

		Basic::$userinput->init();

		$this->_initAction(Basic::$userinput['action']);

		Basic::$action->init();
	}

	protected function _initMultiview()
	{
		$path = parse_url(rawurldecode($_SERVER['REQUEST_URI']), PHP_URL_PATH);
		$path = substr($path, strlen(Basic::$config->Site->baseUrl));

		$GLOBALS['_MULTIVIEW'] = array_filter(explode('/', $path));
	}

	protected function _initSession()
	{
		if (!Basic::$config->Sessions->enabled)
			return false;

		ini_set('session.use_trans_sid', FALSE);
		ini_set('session.use_cookies', TRUE);
		ini_set('session.use_only_cookies', TRUE);
		ini_set('session.gc_maxlifetime', Basic::$config->Sessions->lifetime);

		session_set_cookie_params(Basic::$config->Sessions->lifetime, Basic::$config->Site->baseUrl);

		if (isset(Basic::$config->Sessions->name))
			ini_set('session.name', Basic::$config->Sessions->name);

		session_start();

		if (!isset($_SESSION['hits']))
			$_SESSION['hits'] = 1;
		else
			$_SESSION['hits']++;
	}

	protected function _initDatabase()
	{
		if (!Basic::$config->Database->enabled)
			return false;

		Basic::$database = new Basic_Database;
	}

	protected function _initAction($action, $orgAction = null)
	{
		Basic::$log->start();

		$className = Basic::$config->APPLICATION_NAME .'_Action_'. implode('_', array_map('ucfirst', explode('_', $action)));
		$hasClass = class_exists($className);

		if (!$hasClass)
			$className = Basic::$config->APPLICATION_NAME .'_Action';

		if (!class_exists($className))
			$className = 'Basic_Action';

		if (!$hasClass)
		{
			$classVars = get_class_vars($className);
			$contentType = $classVars['contentType'];

			$hasTemplate = Basic::$template->templateExists($action, array_pop(explode('/', $contentType))) || Basic::$template->templateExists($action);
		}

		if ($hasClass || $hasTemplate)
			$this->action = $action;
		elseif ($action != 'error_404')
		{
			if (!headers_sent())
				header('HTTP/1.0 404 Not Found');

			Basic::$log->write('Class `'. $className .'` not found');

			return $this->_initAction('error_404', $action);
		}
		else
			throw new Basic_Engine_InvalidActionException('The specified action `%s` does not exist', array($orgAction));

		Basic::$action = new $className;

		if (!(Basic::$action instanceof Basic_Action))
			throw new Basic_Engine_MissingMethodsException('The actionclass `%s` must extend Basic_Action', array($className));

		Basic::$log->end();
	}

	public function run()
	{
		if (Basic::$userinput->isValid())
			echo Basic::$action->run();
		else
		{
			if ('POST' == $_SERVER['REQUEST_METHOD'])
				header('Content-Type: '.$this->contentType .'; charset='. $this->encoding, true, 500);

			Basic::$userinput->createForm();
		}
	}

	public function end()
	{
		Basic::$action->end();
	}

	public function handleLastModified()
	{
		if (headers_sent())
			return false;

		$lastModified = ifsetor(Basic::$action->lastModified, 'now');
		$cacheLength = ifsetor(Basic::$action->cacheLength, Basic::$config->Site->defaultCacheLength);

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

	public function redirect($action = null)
	{
		if (!isset($action) && !empty($_SERVER['HTTP_REFERER']))
			$action = $_SERVER['HTTP_REFERER'];
		elseif (FALSE === strpos($action, '://'))
			$action = Basic::$action->baseHref . $action;

		if (!headers_sent())
			header('Location: '.$action);
		else
			echo '<script type="text/javascript">window.location = "'.$action.'";</script>Redirecting you to <a href="'. $action .'">'. $action .'</a>';

		$this->end();

		die();
	}
}
