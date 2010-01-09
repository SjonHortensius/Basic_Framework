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
	var $contents;
	var $regexps;

	var $flags;
	var $sourcefile;
	private $_cachePath;
	private $_sourcePath;

	// Constructor, initialize internal regexps
	public function __construct($variables = array())
	{
		$this->_cachePath = APPLICATION_PATH .'/cache/Templates/';
		$this->_sourcePath = APPLICATION_PATH .'/templates/';

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
				'replace' => TPL_START."echo self::doInclude(\$this, '\\1');".TPL_END
			),

			// echo-variable statement: {(var)}
			'echo' => array(
				'search' => '~\{('.TPL_VAR.')\}~sU'
			),
		);
	}

	function _echo($matches)
	{
		foreach (explode('.', $matches[1]) as $index)
		{
			if (!isset($output))
				$output = (isset($this->engine->action_object->$index)) ? "\$this->engine->action_object->$index" : "\$this->_variables['$index']";
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

	function _echo_variable($matches)
	{
		$matches = array(1 => substr($matches[0], 1, -1));

		if (strpos($matches[1], '{') === FALSE)
			return $this->_echo($matches);

		foreach (explode('.', $matches[1]) as $index)
		{
			if (!isset($output))
				$output = (isset($this->engine->action_object->$index)) ? "\$this->engine->action_object->$index" : "\$this->_variables['$index']";
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

	function _function($matches)
	{
		$output = "'.";
		$arguments = $matches[3];

		// Does the function have multiple arguments?
		if (isset($matches[4]))
			 $arguments = implode("','", explode("{,}", $arguments));

		// Prevent calling functions without arguments with an empty string
		if (!empty($arguments))
			$arguments = "'". $arguments ."'";

		if (method_exists($this->engine->action_object, $matches[2]))
			$output .= "\$this->engine->action_object->";
		elseif (!function_exists($matches[2]))
			throw new TemplateException('Call to undefined function `'. $matches[2] .'` in `'. $this->sourcefile .'`');

		$output .= $matches[2]."(". $arguments .").'";

		return $output;
	}

	function _static_function($matches)
	{
		// We always want an array
		$arguments = explode("{,}", $matches[3]);

		if (method_exists($this->engine->action_object, $matches[2]))
			$function = array($this->engine->action_object, $matches[2]);
		else
			$function = $matches[2];

		return call_user_func_array($function, $arguments);
	}

	function _foreach($matches)
	{
		$output = TPL_START."foreach ('{".$matches[2]."}' as \$this->_variables['". $matches[3] ."']";

		// Vary between optional 'as key=>value' or just 'as value'
		if (!empty($matches[4]))
			$output .= "=>\$this->_variables['". $matches[4] ."']";

		$output .= "){". TPL_END.$matches[5].TPL_START."}".TPL_END;

		return $output;
	}

	function _if_then_else($matches)
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

	// Called by the include statement
	public static function doInclude($parent, $file)
	{
		$object = new self($parent->getVariables());

		$extension = preg_replace('~^.*\.([a-z]+)$~', '\1', $parent->sourcefile);

		try {
			$object->load(APPLICATION_PATH .'/templates/'.  $file .'.'. $extension, $parent->flags);
		} catch (TemplateException $e) {
			return FALSE;
		}

		return $object->show($parent->flags);
	}

	public function getVariables()
	{
		return $this->_variables;
	}

	// Load a file and convert it into native PHP code
	function load($sourcefile, $flags = 0)
	{
		Basic::$log->start();

		$this->sourcefile = $this->_sourcePath . $sourcefile;
		$this->flags = $flags;

		$cachefile = $this->_cachePath . basename($this->sourcefile);

		if (!(TEMPLATE_DONT_STRIP & $this->flags) && is_readable($cachefile) && ($this->_cacheHard || (filemtime($cachefile) > filemtime($this->sourcefile))))
			$this->cachefile = $cachefile;
		else
		{
			try {
				$source = file_get_contents($this->sourcefile);
			} catch (PHPException $e) {
				log::end(basename($this->sourcefile) .' <u>NOT_FOUND</u>');
				throw new TemplateException('unreadable_template', $sourcefile);
			}

			$this->_parse($source);

			try {
				file_put_contents($cachefile, '<?PHP '. $this->contents);
			} catch (PHPException $e) {}
		}

		Basic::$log->end(basename($this->sourcefile). (!isset($this->cachefile) ? ' <u>NOT_CACHED</u>' : ''));

		return TRUE;
	}

	// Main converter, call sub-convertors and perform some cleaning
	private function _parse($contents)
	{
		Basic::$log->start();
		unset($this->cachefile, $this->contents);

		if (!(TEMPLATE_DONT_STRIP & $this->flags))
			$contents = str_replace("\t", '', preg_replace("~(\s{2,}|\n)~", '', $contents));

		$contents = str_replace("\'", "\\\'", $contents);
		$contents = str_replace("'", "\'", $contents);
		$contents = TPL_END.$contents.TPL_START;

		foreach ($this->regexps as $name => $regexp)
			do {
				if (isset($_contents))
					$contents = $_contents;

				if (!isset($regexp['replace']))
					$_contents = preg_replace_callback($regexp['search'], array(&$this, '_'.$name), $contents);
				else
					$_contents = preg_replace($regexp['search'], $regexp['replace'], $contents);

				if (!isset($_contents))
					throw new TemplateException('pcre.backtrack_limit reached!');
			} while ($contents != $_contents);

		$this->contents = $this->clean($contents);

		Basic::$log->end(basename($this->sourcefile));
	}

	// Load a converted template, apply variables and echo the output
	function show($flags = 0)
	{
		Basic::$log->start();

		if (!isset($this->contents) && !isset($this->cachefile))
			throw new TemplateException('no_template_loaded');

		if (!(TEMPLATE_UNBUFFERED & $flags))
			ob_start();

		if (isset($this->cachefile))
			require($this->cachefile);
		elseif (@eval($this->contents) === FALSE && !PRODUCTION_MODE)
			$this->debug();

		Basic::$log->end(basename($this->sourcefile));

		if (TEMPLATE_RETURN_STRING & $flags)
			return ob_get_clean();
		elseif (!(TEMPLATE_UNBUFFERED & $flags))
			ob_end_flush();
	}

	// Clean trash in generated PHP code
	function clean($contents)
	{
		$contents = preg_replace("~([^\\\])''\.~", '\1', $contents);
		$contents = str_replace(".''", "", $contents);
		$contents = str_replace("echo '';", "", $contents);

		return $contents;
	}

	// Get a variable from internal, or an external source
	function _get($name)
	{
		Basic::$log->start();

		foreach (explode('.', $name) as $index)
		{
			if (!isset($result))
@				$result = (isset($this->engine->action_object->$index)) ? $this->engine->action_object->$index : $this->_variables[$index];
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

	function debug()
	{
		echo '<h1>An error occurred while evaluating your templatefile `'. basename($this->sourcefile) .'`</h1>';
		echo '<style>font {font-family: \'Courier new\';}</style>'. highlight_string('<?PHP'. $this->contents .'?>', TRUE) .'</pre>';
	}
}
