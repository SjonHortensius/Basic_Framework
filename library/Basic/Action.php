<?php

class Basic_Action
{
	public $contentType = 'text/html';
	public $encoding = 'ISO-8859-15';
	public $baseHref;

	public $templatesShown = array();
	public $templateName;

/*
	var $userinput;
	var $userinput_config;
	var $userinput_validates;
*/
	public $lastModified;
	public $cacheLength = 0;

//	var $action_is_valid;

	public function __construct()
	{
//		$this->userinput =& $this->engine->userinput->values;
//		$this->userinput_validates =& $this->engine->userinput->required_validates;

		$protocol = (isset($_SERVER['HTTPS']) && 'on' == $_SERVER['HTTPS']) ? 'https' : 'http';
		$this->baseHref = $protocol .'://' . $_SERVER['SERVER_NAME'] . Basic::$config->site['baseUrl'];
	}

	function init()
	{
		if (!isset($this->templateName))
			$this->templateName = Basic::$controller->action;

		Basic::$controller->handleLastModified();

		if (!headers_sent())
			header('Content-Type: '.$this->contentType .'; charset='. $this->encoding);

		try {
			$this->showTemplate('header');
		} catch (TemplateException $e){}
	}

	function run()
	{
		try
		{
			$this->showTemplate($this->template_name);
		} catch (TemplateException $e) {}
	}

	function end()
	{
		$this->timers = Basic::$log->getStatistics();

		try {
			$this->showTemplate('footer');
		} catch (TemplateException $e){}

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

	public function showTemplate($template_name, $flags = 0)
	{
		array_push($this->templatesShown, $template_name);

		$extension = array_pop(explode('/', $this->contentType));

		try {
			Basic::$template->load($template_name .'.'. $extension, $flags);
		} catch (TemplateException $e) {
			Basic::$template->load($template_name, $flags);
		}

		return Basic::$template->show($flags);
	}
}