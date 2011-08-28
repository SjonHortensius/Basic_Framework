<?php

define('TEMPLATE_IGNORE_NON_EXISTING', 1);
define('TEMPLATE_UNBUFFERED', 2);
define('TEMPLATE_RETURN_STRING', 4);

class Basic_Template
{
	protected $_file;
	protected $_flags;
	protected $_sourceFiles;
	protected $_updateCache;
	protected $_extension = 'html';
	protected $_modified;

	const VARIABLE_ELEMENT = '[a-zA-Z\-][a-zA-Z\-_\d]{0,50}';
	const VARIABLE = '[a-zA-Z\-][a-zA-Z.\-_\d]{0,50}';
	const STRING = '"[^"]*"';
	const BLOCK = '[a-zA-Z\-_]{0,50}';
	const COMPARISON = '(?:===|==|<=|<|>=|>|!==|!=|\||&|\^)';
	const BOOLEAN = '(?:\|\||&&)';
	#FIXME: the self::NOTEMPLATE sucks and should be replace by a self::VARIABLE | NO unescaped '-quote
	const NOTEMPLATE = '[^\{\}]*';

	const END = "\necho '";
	const START = "';\n";

	public function __construct()
	{
		$this->_sourceFiles = Basic::$cache->get('TemplateFiles');
		ini_set('pcre.backtrack_limit', 10000000);

		if (!isset($this->_sourceFiles) || (!Basic::$config->PRODUCTION_MODE && 0 == mt_rand(0, 5)))
		{
			$this->_sourceFiles = array();

			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Basic::$config->Template->sourcePath)) as $path => $entry)
				if ($entry->isFile() && false === strpos($path, '/.svn/'))
					$this->_sourceFiles[ substr($path, strlen(Basic::$config->Template->sourcePath)) ] = filemtime($path);

			$this->_updateCache = true;
		}
	}

	protected function _echo($matches)
	{
		if (in_array($matches[1], array('null', 'true', 'false')))
			return "'.$matches[1].'";

		foreach (explode('.', $matches[1]) as $index)
		{
			if (!isset($output))
			{
				if (property_exists(Basic, $index))
					$output = "Basic::\$". $index;
				elseif (property_exists(Basic::$action, $index))
					$output = "Basic::\$action->$index";
				else
					$output = "\$this->$index";
			}
			else
			{
				$result = @eval("return ". $output .";");

				if (is_object($result))
					$output .= "->$index";
				else
					$output .= "['$index']";
			}
		}

		return "'.$output.'";
	}

	protected function _echoVariable($matches)
	{
Basic::debug($matches);
		$matches = array(1 => substr($matches[0], 1, -1));
		$prefix = array();

		if (false === strpos($matches[1], '{'))
			throw new Basic_Template_UnexpectedSyntaxException('Please contact the webmaster');

		foreach (explode('.', $matches[1]) as $index)
		{
			// Make sure we merge {x.{a.b}} back to 1 variable #FIXME: replace explode by preg_split
			if ('{' == substr($index, 0, 1) && '}' != substr($index, -1))
			{
				array_push($prefix, $index);
				continue;
			}

			if ('}' == substr($index, -1) && count($prefix) > 0)
				$index = implode('.', $prefix) .'.'. $index;

			if (!isset($output))
			{
				if (property_exists(Basic, $index))
					$output = "Basic::\$". $index;
				elseif (property_exists(Basic::$action, $index))
					$output = "Basic::\$action->$index";
				else
					$output = "\$this->$index";
			}
			else
			{
				$result = @eval("return ". $output .";");

				if ('{' == substr($index, 0, 1) && '}' == substr($index, -1))
					$index = $this->_echo(array(1=>substr($index, 1, strlen($index)-2)));

				if (is_object($result))
					$output .= "->{'$index'}";
				else
					$output .= "['$index']";
			}
		}

		return "'.$output.'";
	}

	protected function _function($matches)
	{
		$output = "'.";
		$arguments = $matches[3];

		// Does the function have multiple arguments?
		if (isset($matches[4]))
			 $arguments = implode("','", explode("{,}", $arguments));

		// Prevent calling functions without arguments with an empty string
		if (!empty($arguments))
			$arguments = "'". $arguments ."'";

		if (false === strpos($matches[2], '.'))
		{
			if (method_exists(Basic::$action, $matches[2]))
				$function .= "Basic::\$action->";
			elseif (!function_exists($matches[2]))
				throw new Basic_Template_UndefinedFunctionException('Call to undefined function `%s` in `%s`', array($matches[2], $this->_file));

			$function .= $matches[2];
		}
		else
		{
			$parts = explode('.', $matches[2]);
			$method = array_pop($parts);
			$objectString = substr($this->_echo(array(1=>implode('.', $parts))), 2, -2);

			$function = $objectString ."->". $method;
		}

		$output .= $function."(". $arguments .").'";

		return $output;
	}

	protected function _staticFunction($matches)
	{
		// We always want an array
		$arguments = explode("{,}", $matches[3]);

		if (method_exists(Basic::$action, $matches[2]))
			$function = array(Basic::$action, $matches[2]);
		else
			$function = $matches[2];

		$result = call_user_func_array($function, $arguments);
		$result = str_replace("\'", "\\\'", $result);
		$result = str_replace("'", "\'", $result);

		return $result;
	}

	protected function _foreach($matches)
	{
		$output = self::START. "foreach ('{". $matches[2] ."}' as \$this->". $matches[3];

		// Vary between optional 'as key=>value' or just 'as value'
		if (!empty($matches[4]))
			$output .= "=>\$this->". $matches[4];

		$output .= "){". self::END . $matches[5] . self::START ."}". self::END;

		return $output;
	}

	protected function _ifThenElse($matches)
	{
		$output = '';
		foreach (preg_split('~\s*('. self::BOOLEAN .')\s*~', $matches[2], -1, PREG_SPLIT_DELIM_CAPTURE) as $element)
		{
			if (!preg_match('~'. self::BOOLEAN .'~', $element))
			{
				$element = preg_replace('~('. self::VARIABLE.')('. self::COMPARISON.')~', "'{\\1}'\\2", $element);
				$element = preg_replace('~('. self::COMPARISON.')(\d+)~', "\\1 \\2", $element);
				$element = preg_replace('~('. self::COMPARISON.')('. self::VARIABLE.')~', "\\1 '{\\2}'", $element);
				$element = preg_replace('~^(!?)((?<!{)'. self::VARIABLE.'(?!}))$~', "\\1'{\\2}'", $element);
			}

			$output .= $element;
		}

		$output = self::START."if (". $output ."){". self::END.$matches[3]. self::START."}";

		// Optionally include an else-part
		if (isset($matches[4]))
			$output .= "else{". self::END.$matches[4]. self::START."}";

		$output .= self::END;
		return $output;
	}

	protected function _include($file)
	{
		$_file = $this->_file;

		try
		{
			// Relative to current path, or absolute to sourcePath
			if ($file[0] != '/')
				$file = str_replace(Basic::$config->Template->sourcePath, '', dirname($this->_file) .'/') . $file;
			else
				$file = substr($file, 1);

			$output = $this->show($file, $this->_flags);
		}
		catch (Basic_Template_UnreadableTemplateException $e)
		{
			echo $e->getMessage();

			$this->_file = $_file;
			return FALSE;
		}

		$this->_file = $_file;

		return $output;
	}

	public function showFirstFound($files, $flags = 0)
	{
		foreach ($files as $file)
		{
			$file = Basic::resolvePath($file);

			if ($this->templateExists($file))
				return $this->show($file, $flags);
		}

		throw new Basic_Template_CouldNotFindFileException('Could not find any of the templates');
	}

	public function templateExists($file, $extension = null)
	{
		$file .= '.'. ifsetor($extension, $this->_extension);

		if (!array_key_exists($file, $this->_sourceFiles))
		{
			$path = ('/' == $file[0] ? '' : Basic::$config->Template->sourcePath) . $file;
			$this->_sourceFiles[$file] = file_exists($path) ? filemtime($path) : null;
			$this->_updateCache = true;
		}

		return isset($this->_sourceFiles[$file]);
	}

	public function show($file, $flags = 0)
	{
		Basic::$log->start();

		try
		{
			$phpFile = $this->_load($file);
		}
		catch (Basic_Template_UnreadableTemplateException $e)
		{
			Basic::$log->end(basename($file));

			if (TEMPLATE_IGNORE_NON_EXISTING & $flags)
				return;

			throw $e;
		}

		if (!(TEMPLATE_UNBUFFERED & $flags))
			ob_start();

		if (false === require($phpFile))
			throw new Basic_Template_CouldNotParseTemplateException('Could not evaluate your template `%s`', array($file));

		Basic::$log->end(basename($file));

		if (TEMPLATE_RETURN_STRING & $flags)
			return ob_get_clean();
		elseif (!(TEMPLATE_UNBUFFERED & $flags))
			ob_end_flush();
	}

	protected function _load($file, $flags = 0)
	{
		Basic::$log->start();

		if (!$this->templateExists($file))
		{
			Basic::$log->end('NOT_FOUND');
			throw new Basic_Template_UnreadableTemplateException('Cannot read template `%s`', array($file));
		}

		$file .= '.'. $this->_extension;
		$this->_file = Basic::resolvePath(('/' == $file[0] ? '' : Basic::$config->Template->sourcePath) . $file);
		$this->_flags = $flags;
		$phpFile = Basic::$config->Template->cachePath . ('/' == $file ? md5($this->_file) : $file);

		if (!is_readable($phpFile) || (!Basic::$config->PRODUCTION_MODE && filemtime($phpFile) < filemtime($this->_file)))
		{
			if (!isset($this->_sourceFiles[$file]) || $this->_sourceFiles[$file] != filemtime($this->_file))
			{
				$this->_sourceFiles[$file] = filemtime($this->_file);
				$this->_updateCache = true;
			}

			$source = file_get_contents($this->_file);
			$content = $this->_parse($source);

			if (!file_exists(dirname($phpFile)))
				mkdir(dirname($phpFile), 02775, true);

			file_put_contents($phpFile, '<?php '. $content);
		}

		Basic::$log->end(isset($content) ? 'NOT_CACHED' : '');

		return $phpFile;
	}

	protected function _parse($content)
	{
		Basic::$log->start();

		$content = str_replace("\t", '', preg_replace("~(\s{2,}|\n)~", '', $content));
		$content = str_replace("\'", "\\\'", $content);
		$content = str_replace("'", "\'", $content);
		$content = self::END . $content . self::START;

		// Main find-replace regexps
		$regexps = array(
			// comments
			'comment' => array(
				'search' => '~\{\!--(.*)--\}~sU',
				'replace' => '',
			),

			// variable-variable echo statement: {(var).{(othervar)}.(anothervar)}
			'echoVariable' => array(
				'search' => '~\{((\{'. self::VARIABLE .'\}|'. self::VARIABLE_ELEMENT .')\.)*(\{'. self::VARIABLE .'\})(\.(\{'. self::VARIABLE .'\}|'. self::VARIABLE_ELEMENT .'))*\}~sU',
			),

			// static functioncall: {(block)^(function)} {(var)} {,} (data) {(block)/}
			'staticFunction' => array(
				'search' => '~\{('. self::BLOCK .')\^([a-zA-Z_\d]{1,50})\}((?:(?:\{'. self::VARIABLE .'\}|'. self::NOTEMPLATE .')(\{,\})?)*)\{\1/\}~sU',
			),

			// method / functioncall: {(block)@(function)} {(var)} {,} (data) {(block)/}
			'function' => array(
				'search' => '~\{('. self::BLOCK .')@([a-zA-Z_\d.]{1,50})\}((?:(?:\{'. self::VARIABLE .'\}|'. self::NOTEMPLATE .')(\{,\})?)*)\{\1/\}~sU',
			),

			// foreach statement: {(block)*(array):$(var)>$(var)} (foreach-content) {(block)/}
			'foreach' => array(
				'search' => '~\{('. self::BLOCK .')\*('. self::VARIABLE .'):\$('. self::VARIABLE .')(?:>\$('. self::VARIABLE .'))?\}(.*)\{\1\/\}~sU',
			),

			// set statement: {(block)=$(var)} (data) {(block)/}
			'set' => array(
				'search' => '~\{('. self::BLOCK .')=\$('. self::VARIABLE .')\}(.*)\{\1/\}~sU',
				'replace' => self::START."\$this->\\2 = '\\3';". self::END
			),

			// if-then-else statement: {(block)?(!)(var)(comparison)(value|var)} (if-output) {(block):} (else-output) {(block)/}
			'ifThenElse' => array(
				'search' => '~\{('. self::BLOCK.')\?((?:\(?!?'. self::VARIABLE . self::COMPARISON.'?(?:'. self::STRING.'|'. self::VARIABLE.'|[0-9]+)\)?\s*'. self::BOOLEAN.'?\s*)+)\}(.*)(?:\{\1:\}(.*))?\{\1\/\}~sU',
			),

			// include other template: {#(template)}
			'include' => array(
				'search' => '~\{\#([a-zA-Z.\-_\d/]+)\}~sU',
				'replace' => self::START ."\$this->_include('\\1');". self::END
			),

			// echo-variable statement: {(var)}
			'echo' => array(
				'search' => '~\{('. self::VARIABLE.')\}~sU'
			),
		);

		foreach ($regexps as $name => $regexp)
			do
			{
				if (isset($_contents))
					$content = $_contents;

				if (!isset($regexp['replace']))
					$_contents = preg_replace_callback($regexp['search'], array($this, '_'.$name), $content);
				else
					$_contents = preg_replace($regexp['search'], $regexp['replace'], $content);

				if (!isset($_contents))
					throw new Basic_Template_PcreLimitReachedException('`pcre.backtrack_limit` has been reached, please raise this value in your php.ini');
			} while ($content != $_contents);

		$content = $this->_clean($content);

		Basic::$log->end(basename($this->_file));

		return $content;
	}

	// Clean trash in generated PHP code
	protected function _clean($contents)
	{
		$contents = preg_replace("~([^\\\])''\.~", '\1', $contents);
		$contents = preg_replace("~{'([^']+)'}~", '\1', $contents);
		$contents = str_replace(".''", "", $contents);
		$contents = str_replace("echo '';", "", $contents);

		return $contents;
	}

	public function __get($name)
	{
		return Basic::$action->$name;
	}

	public function currentFilename()
	{
		if (substr($this->_file, 0, strlen(Basic::$config->Template->sourcePath)) == Basic::$config->Template->sourcePath)
			return substr($this->_file, strlen(Basic::$config->Template->sourcePath));
		else
			return $this->_file;
	}

	public function setExtension($extension)
	{
		$this->_extension = $extension;
	}

	public function getExtension()
	{
		return $this->_extension;
	}

	public function __destruct()
	{
		if ($this->_updateCache)
			Basic::$cache->set('TemplateFiles', $this->_sourceFiles);
	}
}