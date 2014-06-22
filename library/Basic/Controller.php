<?php

class Basic_Controller
{
	public $action;

	public function init()
	{
		self::_initMultiview();

		if (isset(Basic::$config->Session))
			self::_initSession();

		Basic::$userinput->init();

		self::_initAction(Basic::$userinput['action']);

		Basic::$log->start(get_class(Basic::$action) .'::init');
		Basic::$action->init();
		Basic::$log->end();
	}

	protected static function _initMultiview()
	{
		$base = trim(Basic::$config->Site->baseUrl, '/');
		$offset = ($base == '' ? 0 : count(explode('/', $base)));

		$path = ltrim(rawurldecode($_SERVER['REQUEST_URI']), '/');

		if (false !== strpos($path, '?'))
			$path = strstr($path, '?', true);

		$GLOBALS['_MULTIVIEW'] = array();

		if ($path == '')
			return;

		foreach (explode('/', $path) as $idx => $value)
			$GLOBALS['_MULTIVIEW'][ $idx - $offset ] = ('' == $value) ? null : $value;
	}

	protected static function _initSession()
	{
		if (isset(Basic::$config->Session->name))
			ini_set('session.name', Basic::$config->Session->name);

		session_set_cookie_params(Basic::$config->Session->lifetime, Basic::$config->Site->baseUrl);

		session_start();

		if (!isset($_SESSION['hits']))
			$_SESSION['hits'] = 1;
		else
			$_SESSION['hits']++;
	}

	protected static function _initAction($action)
	{
		Basic::$log->start();

		$class = Basic::$config->APPLICATION_NAME .'_Action_'. implode('_', array_map('ucfirst', explode('_', $action)));
		$hasClass = class_exists($class);
		$hasTemplate = null;

		if (!$hasClass)
		{
			$class = Basic::$config->APPLICATION_NAME .'_Action';

			if (!class_exists($class))
				$class = 'Basic_Action';

			$contentType = get_class_vars($class)['contentType'];
			$hasTemplate = Basic::$template->templateExists($action, array_pop(explode('/', $contentType))) || Basic::$template->templateExists($action);
		}

		try
		{
			$newAction = $class::resolve($action, $hasClass, $hasTemplate);
		}
		catch (Basic_Action_InvalidActionException $e)
		{
			// We need an action to render the exception
			Basic::$controller->action = 'exception';
			Basic::$action = new $class;

			throw $e;
		}

		if (isset($newAction) && $newAction != $action)
		{
			Basic::$log->end($action .' > '. $newAction);

			return self::_initAction($newAction);
		}

		Basic::$controller->action = $action;
		Basic::$action = new $class;

		if (!Basic::$action instanceof Basic_Action)
			throw new Basic_Controller_MissingMethodsException('Class `%s` must extend Basic_Action', array($class));

		Basic::$log->end($action .': '. $class);
	}

	public function run()
	{
		Basic::$log->start();

		if (Basic::$userinput->isValid())
			echo Basic::$action->run();
		elseif ('html' == Basic::$template->getExtension())
		{
			if ('POST' == $_SERVER['REQUEST_METHOD'])
				http_response_code(500);

			echo Basic::$userinput->getHtml();
		}
		else
		{
			$missing = array();
			foreach (Basic::$userinput as $name => $value)
				if (!$value->isValid())
					array_push($missing, $name);

			throw new Basic_Controller_MissingRequiredParametersException('Missing required input: `%s`', array(implode('`, `', $missing)), [], 400);
		}

		Basic::$log->end();
	}

	public function end()
	{
		Basic::$action->end();
	}

	public function redirect($action = null, $permanent = false)
	{
		// Remove any output, our goal is quick redirection
		ob_end_clean();

		if (!isset($action) && !empty($_SERVER['HTTP_REFERER']))
			$action = $_SERVER['HTTP_REFERER'];
		elseif (false === strpos($action, '://'))
			$action = Basic::$action->baseHref . $action;

		if (!headers_sent())
			header('Location: '.$action, true, $permanent ? 301 : 302);
		else
			echo '<script type="text/javascript">window.location = "'.$action.'";</script>Redirecting you to <a href="'. $action .'">'. $action .'</a>';

		// Prevent any output
		ob_start();
		$this->end();
		ob_end_clean();

		die();
	}
}