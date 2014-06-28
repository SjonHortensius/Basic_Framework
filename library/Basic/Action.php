<?php

class Basic_Action
{
	protected $_userinputConfig = array();
	public $formSubmit = 'submit';

	public $contentType = 'text/html';
	public $encoding = 'ISO-8859-15';
	public $baseHref;

	public $templatesShown = array();

	public $lastModified = 'now';
	public $cacheLength = 0;

	public function init()
	{
		$this->baseHref = Basic::$config->Site->protocol .'://' . $_SERVER['SERVER_NAME'] . Basic::$config->Site->baseUrl;

		$this->_handleLastModified();

		Basic::$userinput->run();

		if (!headers_sent())
			header('Content-Type: '.$this->contentType .'; charset='. $this->encoding);

		$this->showTemplate('header');
	}

	public function run()
	{
		$this->showTemplate(Basic::$controller->action);
	}

	public function end()
	{
		$this->showTemplate('footer');

		if ($this->contentType == 'text/html' && !Basic::$config->PRODUCTION_MODE)
		{
			echo '<hr style="clear:both;" /><fieldset><legend>Statistics | <b>'.round(Basic::$template->statistics['time'], 4).' s. | '. round(memory_get_usage()/1024) .' KiB</b></legend>'. Basic::$log->getTimers() .'</fieldset>';
			echo '<fieldset class="log"><legend>Logs</legend><pre>'. Basic::$log->getLogs() .'</pre></fieldset>';
		}
	}

	protected function _handleLastModified()
	{
		if (headers_sent())
			return false;

		if ($this->cacheLength == 0)
		{
			header('Cache-Control: private');
			header('Pragma: no-cache');

			return;
		}

		if (!is_integer($this->lastModified))
			$this->lastModified = strtotime($this->lastModified);
		$expires = strtotime(gmdate('D, d M Y H:i:s \G\M\T', $this->lastModified).' +'.$this->cacheLength);

		if ($expires < time())
			$expires = strtotime('now +'.$this->cacheLength);

		header('Cache-Control: public');
		header('Pragma: Public');

		if ($this->lastModified > 0)
			header('Last-modified: '.gmdate("D, d M Y H:i:s \G\M\T", $this->lastModified));

		header('Expires: '.gmdate("D, d M Y H:i:s \G\M\T", $expires));

		if (!isset($_SERVER['HTTP_IF_MODIFIED_SINCE']))
			return true;

		if (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) < $expires)
			die(http_response_code(304));
	}

	public function getUserinputConfig()
	{
		return $this->_userinputConfig;
	}

	public function showTemplate($templateName, $flags = 0)
	{
		Basic::$template->setExtension(substr($this->contentType, 1+strpos($this->contentType, '/')));

		array_push($this->templatesShown, $templateName);

		return Basic::$template->show($templateName, $flags);
	}

	public static function resolve($action, $hasClass, $hasTemplate)
	{
		if ($hasClass || $hasTemplate)
			return null;

		throw new Basic_Action_InvalidActionException('The specified action `%s` does not exist', array(Basic::$userinput['action']), 404);
	}
}