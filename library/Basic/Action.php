<?php

class Basic_Action
{
	protected $_userinputConfig = array();
//FIXME
	public $formSubmit = 'submit';
	protected $_lastModified = 'now';
	protected $_cacheLength = 0;

	public $contentType = 'text/html';
	public $encoding = 'ISO-8859-15';

	public $baseHref;

	public function init()
	{
		$this->baseHref = Basic::$config->Site->protocol .'://' . $_SERVER['SERVER_NAME'] . Basic::$config->Site->baseUrl;

		$this->_handleLastModified();

		foreach ($this->_userinputConfig as $name => $config)
			Basic::$userinput->$name = $config;

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
			$statistics = Basic::$log->getStatistics();
			echo '<hr style="clear:both;" /><fieldset><legend><b>'.round($statistics['time'], 4).' s | '. $statistics['memory'] .' KiB | '. $statistics['queryCount'] .' Q</b></legend>'. Basic::$log->getTimers() .'</fieldset>';
			echo '<fieldset class="log"><legend>Logs</legend><pre>'. Basic::$log->getLogs() .'</pre></fieldset>';
		}
	}

	protected function _handleLastModified()
	{
		if (headers_sent())
			return false;

		if (0 == $this->_cacheLength)
		{
			header('Cache-Control: private');

			return;
		}

		if (!is_integer($this->_lastModified))
			$this->_lastModified = strtotime($this->_lastModified);
		$expires = strtotime(gmdate('D, d M Y H:i:s \G\M\T', $this->_lastModified).' +'.$this->_cacheLength);

		if ($expires < time())
			$expires = strtotime('now +'.$this->_cacheLength);

		header('Cache-Control: public');

		if ($this->_lastModified > 0)
			header('Last-modified: '.gmdate("D, d M Y H:i:s \G\M\T", $this->_lastModified));

		header('Expires: '.gmdate("D, d M Y H:i:s \G\M\T", $expires));

		if (!isset($_SERVER['HTTP_IF_MODIFIED_SINCE']))
			return true;

		if (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) < $expires)
			die(http_response_code(304));
	}

	public function showTemplate($templateName, $flags = 0)
	{
		Basic::$template->setExtension(substr($this->contentType, 1+strpos($this->contentType, '/')));

		return Basic::$template->show($templateName, $flags);
	}

	public static function resolve($action, $hasClass, $hasTemplate)
	{
		if ($hasClass || $hasTemplate)
			return null;

		throw new Basic_Action_InvalidActionException('The specified action `%s` does not exist', array(Basic::$userinput['action']), 404);
	}
}