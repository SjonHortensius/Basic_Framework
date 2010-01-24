<?php

class Basic_Action
{
	public $contentType = 'text/html';
	public $encoding = 'ISO-8859-15';
	public $baseHref;

	public $templatesShown = array();
	public $templateName;

	public $lastModified;
	public $cacheLength = 0;

	public $userinputConfig = array();

//	var $action_is_valid;

	public function __construct()
	{
		$this->userinput = Basic::$userinput;
		$this->config = Basic::$config;

//		$this->userinput_validates =& $this->engine->userinput->required_validates;

		$protocol = (isset($_SERVER['HTTPS']) && 'on' == $_SERVER['HTTPS']) ? 'https' : 'http';
		$this->baseHref = $protocol .'://' . $_SERVER['SERVER_NAME'] . Basic::$config->site->baseUrl;
	}

	function init()
	{
		if (!isset($this->templateName))
			$this->templateName = Basic::$controller->action;

		Basic::$controller->handleLastModified();

		if (!headers_sent())
			header('Content-Type: '.$this->contentType .'; charset='. $this->encoding);

		try
		{
			$this->showTemplate('header');
		}
		catch (Basic_Template_UnreadableTemplateException $e)
		{}
	}

	function run()
	{
		try
		{
			$this->showTemplate($this->templateName);
		}
		catch (Basic_Template_UnreadableTemplateException $e)
		{}
	}

	function end()
	{
		$this->timers = Basic::$log->getStatistics();

		try
		{
			$this->showTemplate('footer');
		}
		catch (Basic_Template_UnreadableTemplateException $e)
		{}

		if (isset($_GET['debug']))
		{
			$data = $GLOBALS;
			unset($data['GLOBALS'], $data['HTTP_POST_VARS'], $data['HTTP_GET_VARS'], $data['HTTP_SERVER_VARS'], $data['HTTP_COOKIE_VARS'], $data['HTTP_ENV_VARS'], $data['HTTP_POST_FILES']);
			print_r($data);
		}

		if ($this->contentType == 'text/html' && !Basic::$config->PRODUCTION_MODE)
		{
			echo '<hr style="clear:both;"/><fieldset class="log"><legend>Statistics</legend>'. Basic::$log->getTimers() .'</fieldset>';
			echo '<fieldset class="log"><legend>Logs</legend><pre>'. Basic::$log->getLogs() .'</pre></fieldset>';

			if (isset(Basic::$database))
				echo '<fieldset class="log"><legend>Database</legend>'. Basic::$database->_explains .'</fieldset>';
		}
	}

	public function showTemplate($templateName, $flags = 0)
	{
		array_push($this->templatesShown, $templateName);

		$extension = array_pop(explode('/', $this->contentType));

		try
		{
			Basic::$template->load($templateName .'.'. $extension, $flags);
		}
		catch (Basic_Template_UnreadableTemplateException $e)
		{
			Basic::$template->load($templateName, $flags);
		}

		return Basic::$template->show($flags);
	}
}