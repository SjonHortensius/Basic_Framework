<?php

#[AllowDynamicProperties]
class Basic_Config
{
	protected $_file;

	// properties the framework itself uses; listed for code completion / inspection purpose
	public $PRODUCTION_MODE;
	public $APPLICATION_NAME;
	public $Site;
	public $Database;
	public $Template;
	public $Userinput;
	public $Memcache;

	private function __construct(string $file = null)
	{
		if (!isset($file))
			return;

		$this->_file = $file;
		$this->_parse();
	}

	public static function from(string $file): self
	{
		Basic::$log->start();

		$cachedPath = APPLICATION_PATH .'/cache/Config.php';
		if (is_readable($cachedPath))
			$cached = require($cachedPath);
		else
			$cached = [];

		if (!isset($cached[$file]) || filemtime($file) > filemtime($cachedPath))
		{
			$cached[$file] = new self($file);
			file_put_contents($cachedPath, '<?php return '. var_export($cached, true) .';');
		}

		Basic::$log->end($file);
		return $cached[$file];
	}

	public static function __set_state(array $data)
	{
		$config = new self(null);

		// Copy the object properties as we cannot overwrite $this
		foreach ($data as $key => $value)
			$config->$key = $value;

		return $config;
	}

	protected function _parse(): void
	{
		$pointer =& $this;

		foreach (explode("\n", file_get_contents($this->_file)) as $line)
		{
			// empty or comment
			if (empty($line) || ';' == $line[0])
				continue;
			// block-header
			elseif ('[' == $line[0] && ']' == substr($line, -1))
			{
				$pointer =& $this;

				foreach (explode('.', substr($line, 1, -1)) as $part)
				{
					if (!isset($pointer->$part))
						$pointer->$part = new stdClass;

					@$pointer =& $pointer->$part;
				}
			}
			// key=value
			elseif (preg_match('~(.*?)\s*=\s*(["\']?)(.*)\2~', $line, $match))
			{
				$_pointer =& $pointer;
				list(, $key, $quote, $value) = $match;

				// if the value is enclosed in quotes, don't parse it
				if ('' == $quote)
				{
					if (strlen($value) > 0 && strlen($value) == strspn($value, '1234567890'))
						$value = (int)$value;
					elseif (in_array($value, ['true', 'false']))
						$value = ('true' === $value);
					elseif ('null' == $value)
						$value = null;
					else
						$value = str_replace(array_keys(get_defined_constants()), get_defined_constants(), $value);
				}

				// handle array-syntax in key
				$parts = explode('[', $key);
				foreach ($parts as $idx => $part)
				{
					$part = rtrim($part, ']');

					// Skip the last part
					if (1+$idx < count($parts))
						@$pointer =& $pointer->$part;
				}

				// This actually checks / sets the pointer target, not the pointer
				if (!isset($pointer))
					$pointer = ($part == '') ? [] : new stdClass;

				// support for key[]
				if ($part == '')
					array_push($pointer, $value);
				else
@					$pointer->$part = $value;

				$pointer =& $_pointer;
			}
			else
				throw new Basic_Config_CouldNotParseLineException('Could not parse line `%s`', [$line]);
		}
	}
}