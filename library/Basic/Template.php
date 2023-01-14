<?php

#[AllowDynamicProperties]
class Basic_Template
{
	const UNBUFFERED = 1;
	const RETURN_STRING = 2;

	protected $_files = [];
	protected $_currentFile;
	protected $_extension = 'html';
	protected $_shown = [];

	protected static $_regexps = [
		// Comments
		'~\{\!--(.*?)--\}~s' => '',
		// echo variables from Basic::
		'~\{(?:Basic::\$)?(controller|config|userinput|action)([?\w\x7f-\xff\[\'"\]\->()$]+)\}~' => '<?=UNTAINT(Basic::$\1\2)?>',
		// echo variable: {blaat->index}
		'~\{([\w\x7f-\xff\[\'"\]\->(,a-z)$]+)\}~' => '<?=UNTAINT($this->\1??$\1)?>',
		// non-htmlspecialchars variable: {*blaat->index}
		'~\{\*([\w\x7f-\xff\[\'"\]\->(,a-z)$]+)\}~' => '<?=$this->\1?>',
		// block syntax:  {foreach ($array as $var)}class="{var}"{/}
		'~(?:\n|^)(\t*)\{([a-z$][^{}]*?[^;\s])\}(.*?)\n\1\{/\}~s' => '<? \2 { ?>\3<? } ?>',
		// else nested within a block
		'~\{:\}~' => '<? } else { ?>',
		// inline if-statement
		'~\{([a-z$][^{}]*?[^;\s])\}(.*?){/\}~' => '<? \1 { ?>\2<? } ?>',

		// generic statements
		'~\{([^\s][^{}]+[^\s];)\}~U' => '<? \1 ?>',
	];

	public function __construct()
	{
		$this->_files = Basic::$cache->get(self::class .':files:'. dechex(filemtime(Basic::$config->Template->sourcePath)), function(){
			$files = [];
			foreach ([FRAMEWORK_PATH .'/templates/', Basic::$config->Template->sourcePath .'/'] as $base)
				foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $path => $entry)
					$files[ substr($path, strlen($base)) ] = $path;

			return $files;
		});
	}

	/**
	 * Returns the first template that was found in the specified list of options
	 *
	 * @param array $files List of files that should be checked for existence
	 * @param int $flags Flags to pass to @see Basic_Template::show
	 * @return string
	 */
	public function showFirstFound(array $files, int $flags = 0): string
	{
		foreach ($files as $file)
			if ($this->templateExists($file))
				return $this->show($file, $flags);

		throw new Basic_Template_CouldNotFindFileException('Could not find any of the templates (%s)', [implode(', ', $files)]);
	}

	/**
	 * Checks if the specified file exists in any of the template-directories
	 *
	 * @param string $file Relative path of template
	 * @param string|null $extension Optional extension to filter on
	 * @return bool
	 */
	public function templateExists(string $file, string $extension = null): bool
	{
		$file .= '.'. ($extension ?? $this->_extension);

		return isset($this->_files[$file]);
	}

	/**
	 * Render a template and return or output the result
	 *
	 * @param string $file Path of template
	 * @param int $flags Combination of self::UNBUFFERED or self::RETURN_STRING to determine type of output
	 * @return string
	 */
	public function show(string $file, int $flags = 0)
	{
		if (!$this->templateExists($file))
			throw new Basic_Template_UnreadableTemplateException('Cannot read template `%s`', [$file]);

		Basic::$log->start();

		$this->_shown[$file] = true;
		$this->_currentFile = $file;
		$source = $this->_files[ $file .'.'. $this->_extension ];
		$php = Basic::$config->Template->cachePath .'/'. $file .':'. $this->_extension .'.php';

		if (!Basic::$config->PRODUCTION_MODE && is_readable($php) && filemtime($php) < filemtime($source))
			unlink($php);

		if (!(self::UNBUFFERED & $flags))
			ob_start();

		try
		{
			if (false === include($php))
				throw new Basic_Template_CouldNotParseTemplateException('Could not evaluate your template `%s`', [$file]);
		}
		catch (Basic_PhpException $e)
		{
			if (is_readable($php))
			{
				// Prevent partial output from a template
				ob_end_clean();
				throw $e;
			}

			$content = $this->_parse($source);

			if (!is_dir(dirname($php)))
				@mkdir(dirname($php), 02755, true);

			try
			{
				file_put_contents($php, $content);

				if (false === include($php))
					throw new Basic_Template_CouldNotParseTemplateException('Could not evaluate your template `%s`', [$file]);
			}
			catch (Basic_PhpException $e)
			{
				eval($content);
			}
		}

		Basic::$log->end($file);

		if (self::RETURN_STRING & $flags)
			return ob_get_clean();
		elseif (!(self::UNBUFFERED & $flags))
			ob_end_flush();
	}

	protected function _parse(string $source): string
	{
		Basic::$log->start();

		switch ($this->_extension)
		{
			default:
			case 'html': $untaint = 'htmlspecialchars'; break;
			case 'json': $untaint = 'json_encode'; break;
			case 'plain':$untaint = ''; break;
		}

		// escape php-tags in template
		$content = str_replace('<?', '<?=\'<?\'?>', file_get_contents($source));

		foreach (self::$_regexps as $search => $replace)
			do
			{
				if (isset($_content))
					$content = $_content;

				$replace = str_replace('UNTAINT', $untaint, $replace);
				$_content = preg_replace($search, $replace, $content);

				if (!isset($_content))
					throw new Basic_Template_PcreLimitReachedException('`pcre.backtrack_limit` has been reached, please raise this value in your php.ini');
			} while ($content != $_content);

//print(PHP_EOL.str_repeat('=', 99).'RAW:'.PHP_EOL.$content.PHP_EOL.str_repeat('=', 99).'EVALUATED:'.PHP_EOL);eval($content);

		$content = str_replace("\t", '', preg_replace("~(\t{2,}|\n)~", '', $content));

		Basic::$log->end();

		return $content;
	}

	public function getCurrentTemplate(): string
	{
		return $this->_currentFile;
	}

	/**
	 * Set a default extension. Defaults to last part of contentType of Controller, eg. html or json
	 *
	 * @param string $extension
	 */
	public function setExtension(string $extension): void
	{
		$this->_extension = $extension;
	}

	public function getExtension(): string
	{
		return $this->_extension;
	}

	/**
	 * Whether or not the specified template has been shown
	 *
	 * @param string $file
	 * @return bool
	 */
	public function hasShown(string $file): bool
	{
		return isset($this->_shown[$file]);
	}

	/**
	 * Magic getter, allows templates to set/get on $this. Proxies to $action
	 *
	 * @param string $name
	 * @return mixed
	 */
	public function __get($name)
	{
		return Basic::$action->$name ?? null;
	}

	public function __isset($name)
	{
		return isset(Basic::$action->$name);
	}
}