<?php

class Basic_Action
{
	public $contentType = 'text/html';
	public $encoding = 'ISO-8859-15';
	public $base_href;

	var $userinput;
	var $userinput_config;
	var $userinput_validates;

	var $last_modified;
	var $cache_length;

	var $action_is_valid;
	var $templatesShown = array();
	var $template_name;

	public function __construct()
	{
//		$this->userinput =& $this->engine->userinput->values;
//		$this->userinput_validates =& $this->engine->userinput->required_validates;

		$protocol = (isset($_SERVER['HTTPS']) && 'on' == $_SERVER['HTTPS']) ? 'https' : 'http';
		$this->base_href = $protocol .'://' . $_SERVER['SERVER_NAME'] . Basic::$config->site['url_base'] .'/';
	}

	function init()
	{
		if (!isset($this->template_name))
			$this->template_name = $this->engine->action;

		$this->engine->handle_last_modified();

		if (!headers_sent())
			header('Content-Type: '.$this->contentType .'; charset='. $this->encoding);

		try {
			$this->show_template('header');
		} catch (TemplateException $e){}
	}

	function run()
	{
		try
		{
			$this->show_template($this->template_name);
		} catch (TemplateException $e) {}
	}

	function end()
	{
		$this->timers = $this->engine->log->short_output();

		try {
			$this->show_template('footer');
		} catch (TemplateException $e){}

		if (isset($_GET['debug']))
		{
			$data = $GLOBALS;
			unset($data['GLOBALS'], $data['HTTP_POST_VARS'], $data['HTTP_GET_VARS'], $data['HTTP_SERVER_VARS'], $data['HTTP_COOKIE_VARS'], $data['HTTP_ENV_VARS'], $data['HTTP_POST_FILES']);
			engine::pr($data);
		}

		if ($this->content_type == 'text/html' && !Basic::$config->PRODUCTION_MODE)
		{
			echo '<hr style="clear:both;"/><fieldset class="log"><legend>Statistics</legend>'. $this->engine->log->output() .'</fieldset>';
			echo '<fieldset class="log"><legend>Logs</legend><pre>'. implode('<br />', $this->engine->log->logs) .'</pre></fieldset>';

			if (is_object($this->database))
				echo '<fieldset class="log"><legend>Database</legend>'. $this->database->_explains .'</fieldset>';
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