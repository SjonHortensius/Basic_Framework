<?php

class Basic_Action
{
	protected $_userinputConfig = array();

	public $contentType = 'text/html';
	public $encoding = 'ISO-8859-15';
	public $baseHref;

	public $templatesShown = array();
	public $templateName;

	public $lastModified;
	public $cacheLength = 0;

	public function __construct(){}

	public function init()
	{
		$this->baseHref = Basic::$config->Site->protocol .'://' . $_SERVER['SERVER_NAME'] . Basic::$config->Site->baseUrl;

		if (!isset($this->templateName))
			$this->templateName = Basic::$controller->action;

		Basic::$controller->handleLastModified();
		Basic::$userinput->run();

		if (!headers_sent())
			header('Content-Type: '.$this->contentType .'; charset='. $this->encoding);

		$this->showTemplate('header', TEMPLATE_IGNORE_NON_EXISTING);
	}

	public function run()
	{
		$this->showTemplate($this->templateName, TEMPLATE_IGNORE_NON_EXISTING);
	}

	public function end()
	{
		Basic::$template->statistics = Basic::$log->getStatistics();

		$this->showTemplate('footer', TEMPLATE_IGNORE_NON_EXISTING);

		if ($this->contentType == 'text/html' && !Basic::$config->PRODUCTION_MODE)
		{
			echo '<hr style="clear:both;" /><fieldset><legend>Statistics | <b>'.round(Basic::$template->statistics['time'], 4).' s. | '. round(memory_get_usage()/1024) .' KiB</b></legend>'. Basic::$log->getTimers() .'</fieldset>';
			echo '<fieldset class="log"><legend>Logs</legend><pre>'. Basic::$log->getLogs() .'</pre></fieldset>';
		}
	}

	public function getUserinputConfig()
	{
		return $this->_userinputConfig;
	}

	public function showTemplate($templateName, $flags = 0)
	{
		Basic::$template->setExtension(array_pop(explode('/', $this->contentType)));

		array_push($this->templatesShown, $templateName);

		return Basic::$template->show($templateName, $flags);
	}

	// For debugging from templates
	public function debug()
	{
		while(ob_get_level()>1)
			ob_end_clean();

		call_user_func_array(array(Basic, 'debug'), func_get_args());
	}
}