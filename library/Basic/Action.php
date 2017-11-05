<?php

class Basic_Action
{
	public $contentType = 'text/html';
	public $encoding = 'ISO-8859-15';
	public $baseHref;
	public $userinputConfig = [];
	protected $_lastModified = 'now';
	protected $_cacheLength = 0;

	public function init(): void
	{
		$this->_handleLastModified();

		if (!headers_sent())
			header('Content-Type: '.$this->contentType .'; charset='. $this->encoding);

		foreach (Basic::$action->userinputConfig as $name => $config)
			if (!isset(Basic::$userinput->$name))
				Basic::$userinput->$name = $config;

		$this->baseHref = Basic::$config->Site->protocol .'://' . $_SERVER['SERVER_NAME'] . Basic::$config->Site->baseUrl;
		$this->showTemplate('header');
	}

	public function run(): void
	{
		$this->showTemplate(Basic::$userinput['action']);
	}

	public function end(): void
	{
		$this->showTemplate('footer');

		if ($this->contentType == 'text/html' && !Basic::$config->PRODUCTION_MODE)
		{
			$statistics = Basic::$log->getStatistics();
			echo '<hr /><fieldset><legend><b>'.round($statistics['time'], 4).' s | '. $statistics['memory'] .' KiB | '. $statistics['queryCount'] .' Q</b></legend>'. Basic::$log->getTimers() .'</fieldset>';
			echo '<fieldset class="log"><legend>Logs</legend><pre>'. Basic::$log->getLogs() .'</pre></fieldset>';
		}
	}

	protected function _handleLastModified(): ?bool
	{
		if (headers_sent())
			return false;

		if (0 == $this->_cacheLength)
		{
			header('Cache-Control: private');

			return null;
		}

		if (!is_integer($this->_lastModified))
			$this->_lastModified = strtotime($this->_lastModified);
		$expires = strtotime(gmdate('D, d M Y H:i:s \G\M\T', $this->_lastModified).' +'.$this->_cacheLength);

		if ($expires < time())
			$expires = strtotime('now +'.$this->_cacheLength);

		header('Cache-Control: public');

		if ($this->_lastModified > 0)
			header('Last-modified: '.gmdate('D, d M Y H:i:s \G\M\T', $this->_lastModified));

		header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', $expires));

		if (!isset($_SERVER['HTTP_IF_MODIFIED_SINCE']))
			return true;

		if (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) < $expires)
			die(http_response_code(304));
	}

	public function showTemplate(string $templateName, int $flags = 0): void
	{
		Basic::$template->setExtension(explode('/', $this->contentType)[1]);

		Basic::$template->show($templateName, $flags);
	}

	public static function getRoute(): string
	{
		return strtolower(implode('_', array_slice(explode('_', get_called_class()), 2)));
	}

	public static function resolve(string $action, bool $hasClass, bool $hasTemplate): ?string
	{
		if ($hasClass || $hasTemplate)
			return null;

		throw new Basic_Action_InvalidActionException('The specified action `%s` does not exist', array(Basic::$userinput['action']), 404);
	}
}