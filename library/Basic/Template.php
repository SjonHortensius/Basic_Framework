<?php

define('TEMPLATE_DONT_STRIP', 1);
define('TEMPLATE_UNBUFFERED', 2);
define('TEMPLATE_RETURN_STRING', 4);

class Basic_Template
{
	protected $_variables = array();
	protected $_file;
	protected $_cacheFile;
	protected $_flags;
	protected $_sourceFiles;
	protected $_extension = 'html';
	protected $_modified;

	const VARIABLE_ELEMENT = '[a-zA-Z\-_][a-zA-Z\-_\d]{0,50}';
	const VARIABLE = '[a-zA-Z\-_][a-zA-Z.\-_\d]{0,50}';
	const STRING = '"[^"]*"';
	const BLOCK = '[a-zA-Z\-_]{0,50}';
	const COMPARISON = '(?:===|==|<=|<|>=|>|!==|!=|\||&|\^)';
	const BOOLEAN = '(?:\|\||&&)';
	#FIXME: the self::NOTEMPLATE sucks and should be replace by a self::VARIABLE | NO unescaped '-quote
	const NOTEMPLATE = '[^\{\}]*';

	const END = "\necho '";
	const START = "';\n";

	// Constructor, initialize internal regexps
	public function __construct()
	{
		$this->_modified = filemtime(Basic::$config->Template->sourcePath);

		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Basic::$config->Template->sourcePath)) as $path => $entry)
			if ($entry->isFile() && false === strpos($path, '/.svn/'))
				$this->_sourceFiles[ substr($path, strlen(Basic::$config->Template->sourcePath)) ] = true;

		$this->_variables['config'] = Basic::$config;
		$this->_variables['action'] =& Basic::$controller->action;
		$this->_variables['config'] = Basic::$config;
		$this->_variables['userinput'] = Basic::$userinput;
	}

	protected function _echo($matches)
	{
		if (in_array($matches[1], array('null', 'true', 'false')))
			return "'.$matches[1].'";

		foreach (explode('.', $matches[1]) as $index)
		{
			if (!isset($output))
				$output = (isset(Basic::$action->$index)) ? "Basic::\$action->$index" : "\$this->_variables['$index']";
			else
			{
				$result = @eval("return ". $output .";");

				if (is_object($result))
					$output .= "->$index";
				else
					$output .= "['$index']";
			}
		}

		return "'.(isset($output)?$output:\$this->_get('{$matches[1]}')).'";
	}

	protected function _echoVariable($matches)
	{
		$matches = array(1 => substr($matches[0], 1, -1));
		$prefix = array();

		if (strpos($matches[1], '{') === FALSE)
			return $this->_echo($matches);

		foreach (explode('.', $matches[1]) as $index)
		{
			// Make sure we merge {a.b} back to 1 variable #FIXME: replace explode by preg_split
			if ('{' == substr($index, 0, 1) && '}' != substr($index, -1))
			{
				array_push($prefix, $index);
				continue;
			}

			if ('}' == substr($index, -1) && count($prefix) > 0)
				$index = implode('.', $prefix) .'.'. $index;

			if (!isset($output))
				$output = (isset(Basic::$action->$index)) ? "Basic::\$action->$index" : "\$this->_variables['$index']";
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

		return "'.(isset($output)?$output:\$this->_get('{$matches[1]}')).'";
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

		if (method_exists(Basic::$action, $matches[2]))
			$output .= "Basic::\$action->";
		elseif (!function_exists($matches[2]))
			throw new Basic_Template_UndefinedFunctionException('Call to undefined function `%s` in `%s`', array($matches[2], $this->_file));

		$output .= $matches[2]."(". $arguments .").'";

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

		return call_user_func_array($function, $arguments);
	}

	protected function _foreach($matches)
	{
		$output = self::START. "foreach ('{". $matches[2] ."}' as \$this->_variables['". $matches[3] ."']";

		// Vary between optional 'as key=>value' or just 'as value'
		if (!empty($matches[4]))
			$output .= "=>\$this->_variables['". $matches[4] ."']";

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
		$_variables = $this->_variables;
		$_file = $this->_file;

		try
		{
			// Include relative to current path
			if ($file{0} != '/')
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

			if (!$this->templateExists($file))
				continue;

			return $this->show($file, $flags);
		}
	}

	public function templateExists($file, $extension = null)
	{
		if (!isset($extension))
			$extension = $this->_extension;

		if ('/' != $file{0})
			return isset($this->_sourceFiles[ $file .'.'. $extension ]);
		else
			return file_exists($file .'.'. $extension);
	}

	// Load a file and convert it into native PHP code
	protected function _load($file, $flags = 0)
	{
		Basic::$log->start();
		$this->_file = $file .'.'. $this->_extension;
		$this->_flags = $flags;

		if ('/' != $this->_file{0})
		{
			$cachefile = Basic::$config->Template->cachePath . $this->_file;
			$this->_file = Basic::$config->Template->sourcePath . $this->_file;
		} else
			$cachefile = Basic::$config->Template->cachePath . md5($this->_file);

		if (!$this->templateExists($file) || !is_readable($this->_file))
		{
			Basic::$log->end(basename($this->_file) .' <u title="'. dirname($this->_file) .'">NOT_FOUND</u>');
			throw new Basic_Template_UnreadableTemplateException('Cannot read template `%s`', array($this->_file));
		}

		if ((TEMPLATE_DONT_STRIP & $this->_flags) || !is_readable($cachefile) || (!Basic::$config->PRODUCTION_MODE && filemtime($cachefile) < filemtime($this->_file)))
		{
			$source = file_get_contents($this->_file);

			$content = $this->_parse($source);

			try
			{
				if (!is_dir(dirname($cachefile)))
				{
					$old = umask(0);
					mkdir(dirname($cachefile), 02775, true);
					umask($old);
				}

				file_put_contents($cachefile, '<?php '. $content);
			}
			catch (Basic_PhpException $e) {}

			unset($cachefile);
		}

		Basic::$log->end(basename($this->_file). (!isset($cachefile) ? ' <u>NOT_CACHED</u>' : ''));

		return array($cachefile, $content);
	}

	// Main converter, call sub-convertors and perform some cleaning
	protected function _parse($content)
	{
		Basic::$log->start();

		if (!(TEMPLATE_DONT_STRIP & $this->_flags))
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

			// external functioncall: {(block)@(function)} {(var)} {,} (data) {(block)/}
			'function' => array(
				'search' => '~\{('. self::BLOCK .')@([a-zA-Z_\d]{1,50})\}((?:(?:\{'. self::VARIABLE .'\}|'. self::NOTEMPLATE .')(\{,\})?)*)\{\1/\}~sU',
			),

			// foreach statement: {(block)*(array):$(var)>$(var)} (foreach-content) {(block)/}
			'foreach' => array(
				'search' => '~\{('. self::BLOCK .')\*('. self::VARIABLE .'):\$('. self::VARIABLE .')(?:>\$('. self::VARIABLE .'))?\}(.*)\{\1\/\}~sU',
			),

			// set statement: {(block)=$(var)} (data) {(block)/}
			'set' => array(
				'search' => '~\{('. self::BLOCK .')=\$('. self::VARIABLE .')\}(.*)\{\1/\}~sU',
				'replace' => self::START."\$this->_variables['\\2'] = '\\3';". self::END
			),

			// if-then-else statement: {(block)?(!)(var)(comparison)(value|var)} (if-output) {(block):} (else-output) {(block)/}
			'ifThenElse' => array(
				'search' => '~\{('. self::BLOCK.')\?((?:\(?!?'. self::VARIABLE . self::COMPARISON.'?(?:'. self::STRING.'|'. self::VARIABLE.'|[0-9]+)\)?\s*'. self::BOOLEAN.'?\s*)+)\}(.*)(?:\{\1:\}(.*))?\{\1\/\}~sU',
			),

			// include other template: {#(template)}
			'include' => array(
				'search' => '~\{\#([a-zA-Z.\-_\d/]{0,50})\}~sU',
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
					$_contents = preg_replace_callback($regexp['search'], array(&$this, '_'.$name), $content);
				else
					$_contents = preg_replace($regexp['search'], $regexp['replace'], $content);

				if (!isset($_contents))
					throw new Basic_Template_PcreLimitReacedException('`pcre.backtrack_limit` has been reached, please raise this value in your php.ini');
			} while ($content != $_contents);

		$content = $this->_clean($content);

		Basic::$log->end(basename($this->_file));

		return $content;
	}

	// Load a converted template, apply variables and echo the output
	public function show($file, $flags = 0)
	{
		Basic::$log->start();

		//FIXME: temporary
		if (substr($file, -(strlen($this->_extension)+1)) == '.'. $this->_extension)
			throw new Basic_Template_DeprecatedException('show() no longer expects an extension');

		list($cacheFile, $content) = $this->_load($file);

		if (!(TEMPLATE_UNBUFFERED & $flags))
			ob_start();

		if (isset($cacheFile))
			require($cacheFile);
		elseif (@eval($content) === FALSE)
		{
			echo '<h1>An error occurred while evaluating your templatefile `'. basename($this->_file) .'`</h1>';

			if (!Basic::$config->PRODUCTION_MODE)
				echo '<pre>'. highlight_string('<?PHP'. $content .'?>', TRUE) .'</pre>';
		}

		Basic::$log->end(basename($file));

		if (TEMPLATE_RETURN_STRING & $flags)
			return ob_get_clean();
		elseif (!(TEMPLATE_UNBUFFERED & $flags))
			ob_end_flush();
	}

	// Clean trash in generated PHP code
	protected function _clean($contents)
	{
		$contents = preg_replace("~([^\\\])''\.~", '\1', $contents);
		$contents = str_replace(".''", "", $contents);
		$contents = str_replace("echo '';", "", $contents);

		return $contents;
	}

	// Get a variable from internal, or an external source
	protected function _get($name)
	{
		Basic::$log->start();

		foreach (explode('.', $name) as $index)
		{
			if (!isset($result))
@				$result = ifsetor(Basic::$action->$index, $this->_variables[$index]);
			else
			{
				if (is_object($result))
@					$result =& $result->$index;
				else
@					$result =& $result[$index];
			}
		}

		Basic::$log->end($name);

		return $result;
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

	public function __isset($variable)
	{
		return isset($this->_variables[ $variable ]);
	}

	public function __get($variable)
	{
		return $this->_variables[ $variable ];
	}

	public function __set($variable, $value)
	{
		$this->_variables[ $variable ] = $value;
	}

	public function __sleep()
	{
		return array('_sourceFiles');
	}

	public function __wakeup()
	{
		if (!Basic::$config->PRODUCTION_MODE && $this->_modified != filemtime(Basic::$config->Template->sourcePath))
			throw new Basic_StaleCacheException('Internal error: the cache is stale');

		$this->_variables['config'] = Basic::$config;
		$this->_variables['action'] =& Basic::$controller->action;
		$this->_variables['config'] = Basic::$config;
		$this->_variables['userinput'] = Basic::$userinput;
	}
}