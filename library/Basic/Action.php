<?php

class Basic_Action
{
	protected $_userinputConfig = array();

	public $contentType = 'text/html';
	public $encoding = 'ISO-8859-15';
	public $baseHref;

	public $templatesShown = array();

	public $lastModified;
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
			return;

		$lastModified = ifsetor(Basic::$action->lastModified, 'now');
		$cacheLength = ifsetor(Basic::$action->cacheLength, Basic::$config->Site->defaultCacheLength);

		if ($cacheLength == 0)
		{
			header('Cache-Control: private');
			header('Pragma: no-cache');

			return;
		}

		if (!is_integer($lastModified))
			$lastModified = strtotime($lastModified);
		$expireDate = strtotime(gmdate('D, d M Y H:i:s \G\M\T', $lastModified).' +'.$cacheLength);

		header('Cache-Control: public');

		if ($lastModified > 0)
			header('Last-modified: '.gmdate("D, d M Y H:i:s \G\M\T", $lastModified));

		header('Expires: '.gmdate("D, d M Y H:i:s \G\M\T", $expireDate));

		if (!isset($_SERVER['HTTP_IF_MODIFIED_SINCE']))
			return;

		if (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) < $expireDate)
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

		// This would happen if the 404 doesn't exist either, so we need to prevent recursion
		if ($action == 'error_404')
			throw new Basic_Action_InvalidActionException('The specified action `%s` does not exist', array(Basic::$userinput['action']));

		if (!headers_sent())
			http_response_code(404);

		return 'error_404';
	}
}