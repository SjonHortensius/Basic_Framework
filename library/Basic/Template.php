<?php

class Basic_Template
{
	const UNBUFFERED = 1;
	const RETURN_STRING = 2;

	protected $_files = array();
	protected $_currentFile;
	protected $_extension = 'html';

	protected static $_regexps = array(
		// echo variable: {blaat->index}
		'~\{([\w\x7f-\xff\[\'"\]\->()$]+)\}~' => '<?=$this->\1?>',
		// if/else block: {if ($var)}class="var"{:}id="var"{/}
		'~((?:\n|^)\t*)\{([a-z$][^{}]*?[^;\s])\}(.*?)\1\{:\}(.*?)\1\{/\}~s' => '<? \2 { ?>\3<? } else { ?>\4<? } ?>',
		// block syntax:  {foreach ($array as $var)}class="{var}"{/}
		'~((?:\n|^)\t*)\{([a-z$][^{}]*?[^;\s])\}(.*?)\1\{/\}~s' => '<? \2 { ?>\3<? } ?>',
		// inline if-statement
		'~\{([a-z$][^{}]*?[^;\s])\}(.*?){/\}~' => '<? \1 { ?>\2<? } ?>',

		// special variables from Basic::
		'~(?<!Basic::)\$(controller|config|userinput|action)~' => 'Basic::$\1',

		// generic statements
		'~\{([^\s][^{}]+[^\s];)\}~U' => '<? \1 ?>',
	);

	public function __construct()
	{
		if (!ini_get('short_open_tag') || ini_get('asp_tags'))
			throw new Basic_TemplateException;

		Basic::$config->Template->sourcePath .= '/';
		Basic::$config->Template->cachePath .= '/';

		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$cache->delete('Basic_Template::files');

		try
		{
			$this->_files = Basic::$cache->get('Basic_Template::files');
		}
		catch (Basic_Memcache_ItemNotFoundException $e)
		{
			foreach (array(FRAMEWORK_PATH.'/templates/', Basic::$config->Template->sourcePath) as $base)
				foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $path => $entry)
					$this->_files[ substr($path, strlen($base)) ] = $path;

			Basic::$cache->set('Basic_Template::files', $this->_files);
		}
	}

	public function showFirstFound($files, $flags = 0)
	{
		foreach ($files as $file)
			if ($this->templateExists($file))
				return $this->show($file, $flags);

		throw new Basic_Template_CouldNotFindFileException('Could not find any of the templates');
	}

	public function templateExists($file, $extension = null)
	{
		$file .= '.'. ifsetor($extension, $this->_extension);

		return isset($this->_files[$file]);
	}

	public function show($file, $flags = 0)
	{
		if (!$this->templateExists($file))
			throw new Basic_Template_UnreadableTemplateException('Cannot read template `%s`', array($file));

		Basic::$log->start();

		$this->_currentFile = $file;
		$source = $this->_files[ $file .'.'. $this->_extension  ];
		$php = Basic::$config->Template->cachePath . $file .'.'. $this->_extension;

		if (!is_readable($php) || (!Basic::$config->PRODUCTION_MODE && filemtime($php) < filemtime($source)))
		{
			$content = $this->_parse($source);

			if (!is_dir(dirname($php)))
				mkdir(dirname($php), 02755, true);

			file_put_contents($php, $content);
		}

		if (!(self::UNBUFFERED & $flags))
			ob_start();

		if (false === require($php))
			throw new Basic_Template_CouldNotParseTemplateException('Could not evaluate your template `%s`', array($file));

		Basic::$log->end(basename($file));

		if (self::RETURN_STRING & $flags)
			return ob_get_clean();
		elseif (!(self::UNBUFFERED & $flags))
			ob_end_flush();
	}

	protected function _parse($source)
	{
		Basic::$log->start();

		// escape php-tags in template
		$content = str_replace('?', '<?=\'?\'?>', file_get_contents($source));

		foreach (self::$_regexps as $search => $replace)
			do
			{
				if (isset($_content))
					$content = $_content;

				$_content = preg_replace($search, $replace, $content);

				if (!isset($_content))
					throw new Basic_Template_PcreLimitReachedException('`pcre.backtrack_limit` has been reached, please raise this value in your php.ini');
			} while ($content != $_content);

//print(PHP_EOL.str_repeat('=', 99).'RAW:'.PHP_EOL.$content.PHP_EOL.str_repeat('=', 99).'EVALUATED:'.PHP_EOL);eval($content);

		$content = str_replace("\t", '', preg_replace("~(\t{2,}|\n)~", '', $content));

		Basic::$log->end();

		return $content;
	}

	public function getCurrentTemplate()
	{
		return $this->_currentFile;
	}

	public function setExtension($extension)
	{
		$this->_extension = $extension;
	}

	public function getExtension()
	{
		return $this->_extension;
	}

	public function __get($name)
	{
		return Basic::$action->$name;
	}
}