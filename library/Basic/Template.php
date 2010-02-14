<?php
// simpleTemplate.class by Sjon Hortensius, Sjon@hortensius.net

define('TPL_VAR_ELEMENT', '[a-zA-Z\-_][a-zA-Z\-_\d]{0,50}');
define('TPL_VAR', '[a-zA-Z\-_][a-zA-Z.\-_\d]{0,50}');
//define('TPL_VAR', '(?:'. TPL_VAR_ELEMENT .'\.?)+');
define('TPL_STRING', '"[^"]*"');
define('TPL_BLOCK', '[a-zA-Z\-_]{0,50}');
define('TPL_COMPARISON', '(?:===|==|<=|<|>=|>|!==|!=)');
define('TPL_BOOLEAN', '(?:\|\||&&)');
define('TPL_NOTEMPLATE', '[^\{\}]*');

define('TPL_END', "\necho '");
define('TPL_START', "';\n");

// Define flags
define('TEMPLATE_DONT_STRIP', 1);
define('TEMPLATE_UNBUFFERED', 2);
define('TEMPLATE_RETURN_STRING', 4);

class Basic_Template
{
	private $_variables;
	private $_cacheHard;
	private $_content;
	private $_file;
	private $_cache;
	private $_flags;

	var $regexps = array();

	// Constructor, initialize internal regexps
	public function __construct($variables = array())
	{
		Basic::$config->Templates->cachePath = APPLICATION_PATH .'/cache/Templates/';
		Basic::$config->Templates->sourcePath = APPLICATION_PATH .'/templates/';

		$this->_cacheHard = Basic::$config->PRODUCTION_MODE;

		$this->_variables = $variables;
		$this->_variables['config'] =& Basic::$config;
		$this->_variables['action'] =& Basic::$controller->action;

		// Main find-replace regexps
		$this->regexps = array(
			// comments
			'comment' => array(
				'search' => '~\{\!--(.*)--\}~sU',
				'replace' => '',
			),

			// variable-variable echo statement: {(var).{(othervar)}.(anothervar)}
			'echo_variable' => array(
				'search' => '~\{('. TPL_VAR_ELEMENT .')(\.(\{'. TPL_VAR .'\}|'. TPL_VAR_ELEMENT .'))+\}~sU',
			),

			// static functioncall: {(block)^(function)} {(var)} {,} (data) {(block)/}
			#FIXME: the TPL_NOTEMPLATE sucks and should be replace by a TPL_VAR | NO unescaped '-quote
			'static_function' => array(
				'search' => '~\{('.TPL_BLOCK.')\^([a-zA-Z_\d]{1,50})\}((?:(?:\{'.TPL_VAR.'\}|'.TPL_NOTEMPLATE.')(\{,\})?)*)\{\1/\}~sU',
			),

			// external functioncall: {(block)@(function)} {(var)} {,} (data) {(block)/}
			#FIXME: the TPL_NOTEMPLATE sucks and should be replace by a TPL_VAR | NO unescaped '-quote
			'function' => array(
				'search' => '~\{('.TPL_BLOCK.')@([a-zA-Z_\d]{1,50})\}((?:(?:\{'.TPL_VAR.'\}|'.TPL_NOTEMPLATE.')(\{,\})?)*)\{\1/\}~sU',
			),

			// foreach statement: {(block)*(array):$(var)>$(var)} (foreach-content) {(block)/}
			'foreach' => array(
				'search' => '~\{('.TPL_BLOCK.')\*('.TPL_VAR.'):\$('.TPL_VAR.')(?:>\$('.TPL_VAR.'))?\}(.*)\{\1\/\}~sU',
			),

			// set statement: {(block)=$(var)} (data) {(block)/}
			'set' => array(
				'search' => '~\{('.TPL_BLOCK.')=\$('.TPL_VAR.')\}(.*)\{\1/\}~sU',
				'replace' => TPL_START."\$this->_variables['\\2'] = '\\3';".TPL_END
			),

			// if-then-else statement: {(block)?(!)(var)(comparison)(value|var)} (if-output) {(block):} (else-output) {(block)/}
			'if_then_else' => array(
				'search' => '~\{('.TPL_BLOCK.')\?((?:\(?!?'.TPL_VAR.TPL_COMPARISON.'?(?:'.TPL_STRING.'|'.TPL_VAR.'|[0-9]+)\)?\s*'.TPL_BOOLEAN.'?\s*)+)\}(.*)(?:\{\1:\}(.*))?\{\1\/\}~sU',
			),

			// include other template: {#(template)}
			'include' => array(
				'search' => '~\{\#('.TPL_VAR.')\}~sU',
				'replace' => TPL_START ."\$this->_include('\\1');". TPL_END
			),

			// echo-variable statement: {(var)}
			'echo' => array(
				'search' => '~\{('.TPL_VAR.')\}~sU'
			),
		);
	}

	private function _echo($matches)
	{
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

	private function _echo_variable($matches)
	{
		$matches = array(1 => substr($matches[0], 1, -1));

		if (strpos($matches[1], '{') === FALSE)
			return $this->_echo($matches);

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
				{
					if (substr($index, 0, 1) == '{')
						$index = $this->_echo(array(1=>substr($index, 1, strlen($index)-2)));

					$output .= "['$index']";
				}
			}
		}

		return "'.(isset($output)?$output:\$this->_get('{$matches[1]}')).'";
	}

	private function _function($matches)
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

	private function _static_function($matches)
	{
		// We always want an array
		$arguments = explode("{,}", $matches[3]);

		if (method_exists(Basic::$action, $matches[2]))
			$function = array(Basic::$action, $matches[2]);
		else
			$function = $matches[2];

		return call_user_func_array($function, $arguments);
	}

	private function _foreach($matches)
	{
		$output = TPL_START."foreach ('{".$matches[2]."}' as \$this->_variables['". $matches[3] ."']";

		// Vary between optional 'as key=>value' or just 'as value'
		if (!empty($matches[4]))
			$output .= "=>\$this->_variables['". $matches[4] ."']";

		$output .= "){". TPL_END.$matches[5].TPL_START."}".TPL_END;

		return $output;
	}

	private function _if_then_else($matches)
	{
		$output = '';
		foreach (preg_split('~\s*('. TPL_BOOLEAN .')\s*~', $matches[2], -1, PREG_SPLIT_DELIM_CAPTURE) as $element)
		{
			if (!preg_match('~'. TPL_BOOLEAN .'~', $element))
			{
				$element = preg_replace('~('.TPL_VAR.')('.TPL_COMPARISON.')~', "'{\\1}'\\2", $element);
				$element = preg_replace('~('.TPL_COMPARISON.')(\d+)~', "\\1 \\2", $element);
				$element = preg_replace('~('.TPL_COMPARISON.')('.TPL_VAR.')~', "\\1 '{\\2}'", $element);
				$element = preg_replace('~^(!?)((?<!{)'.TPL_VAR.'(?!}))$~', "\\1'{\\2}'", $element);
			}

			$output .= $element;
		}

		$output = TPL_START."if (". $output ."){".TPL_END.$matches[3].TPL_START."}";

		// Optionally include an else-part
		if (isset($matches[4]))
			$output .= "else{".TPL_END.$matches[4].TPL_START."}";

		$output .= TPL_END;
		return $output;
	}

	private function _include($file)
	{
		$_variables = $this->_variables;

		$extension = preg_replace('~^.*\.([a-z]+)$~', '\1', $this->_file);

		try
		{
			$this->load($file .'.'. $extension, $this->_flags);
		}
		catch (Basic_Template_UnreadableTemplateException $e)
		{
			return FALSE;
		}

		$output = $this->show($this->_flags);

		// reset the variables list
		$this->_variables = $_variables;

		return $output;
	}

	// Load a file and convert it into native PHP code
	public function load($file, $flags = 0)
	{
		Basic::$log->start();
		$this->_file = $file;

		if ('/' != $this->_file{0})
			$this->_file = Basic::$config->Templates->sourcePath . $this->_file;
		$this->_flags = $flags;

		$cachefile = Basic::$config->Templates->cachePath . basename($this->_file);

		if (!(TEMPLATE_DONT_STRIP & $this->_flags) && is_readable($cachefile) && ($this->_cacheHard || (filemtime($cachefile) > filemtime($this->_file))))
			$this->_cache = $cachefile;
		else
		{
			try
			{
				$source = file_get_contents($this->_file);
			}
			catch (Basic_PhpException $e)
			{
				Basic::$log->end(basename($this->_file) .' <u>NOT_FOUND</u>');
				throw new Basic_Template_UnreadableTemplateException('Cannot read template `%s`', array($file));
			}

			$this->_parse($source);

			try
			{
//				mkdir(dirname($cachefile));
				file_put_contents($cachefile, '<?PHP '. $this->_content);
			}
			catch (Basic_PhpException $e) {}
		}

		Basic::$log->end(basename($this->_file). (!isset($this->_cache) ? ' <u>NOT_CACHED</u>' : ''));

		return TRUE;
	}

	// Main converter, call sub-convertors and perform some cleaning
	private function _parse($content)
	{
		Basic::$log->start();
		unset($this->_cache, $this->_content);

		if (!(TEMPLATE_DONT_STRIP & $this->_flags))
			$content = str_replace("\t", '', preg_replace("~(\s{2,}|\n)~", '', $content));

		$content = str_replace("\'", "\\\'", $content);
		$content = str_replace("'", "\'", $content);
		$content = TPL_END.$content.TPL_START;

		foreach ($this->regexps as $name => $regexp)
			do {
				if (isset($_contents))
					$content = $_contents;

				if (!isset($regexp['replace']))
					$_contents = preg_replace_callback($regexp['search'], array(&$this, '_'.$name), $content);
				else
					$_contents = preg_replace($regexp['search'], $regexp['replace'], $content);

				if (!isset($_contents))
					throw new Basic_Template_PcreLimitReacedException('`pcre.backtrack_limit` has been reached, please raise this value in your php.ini');
			} while ($content != $_contents);

		$this->_content = $this->_clean($content);

		Basic::$log->end(basename($this->_file));
	}

	// Load a converted template, apply variables and echo the output
	public function show($flags = 0)
	{
		Basic::$log->start();

		if (!isset($this->_content) && !isset($this->_cache))
			throw new Basic_Template_NoLoadedTemplateException('No template has been loaded yet, cannot show anything');

		if (!(TEMPLATE_UNBUFFERED & $flags))
			ob_start();

		if (isset($this->_cache))
			require($this->_cache);
		elseif (@eval($this->_content) === FALSE && !Basic::$config->PRODUCTION_MODE)
			$this->debug();

		Basic::$log->end(basename($this->_file));

		if (TEMPLATE_RETURN_STRING & $flags)
			return ob_get_clean();
		elseif (!(TEMPLATE_UNBUFFERED & $flags))
			ob_end_flush();
	}

	// Clean trash in generated PHP code
	private function _clean($contents)
	{
		$contents = preg_replace("~([^\\\])''\.~", '\1', $contents);
		$contents = str_replace(".''", "", $contents);
		$contents = str_replace("echo '';", "", $contents);

		return $contents;
	}

	// Get a variable from internal, or an external source
	private function _get($name)
	{
		Basic::$log->start();

		foreach (explode('.', $name) as $index)
		{
			if (!isset($result))
@				$result = (isset(Basic::$action->$index)) ? Basic::$action->$index : $this->_variables[$index];
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

	public function debug()
	{
		echo '<h1>An error occurred while evaluating your templatefile `'. basename($this->_file) .'`</h1>';
		echo '<style>font {font-family: \'Courier new\';}</style>'. highlight_string('<?PHP'. $this->_content .'?>', TRUE) .'</pre>';
	}

	public function getFile()
	{
		return $this->_file;
	}

	public function getVariable($variable)
	{
		return $this->_variables[ $variable ];
	}
}