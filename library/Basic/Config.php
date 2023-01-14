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

	public function __construct(string $file = null)
	{
		Basic::$log->start();

		$this->_file = $file ?? APPLICATION_PATH .'/config.ini';

		$cache = Basic::$cache->get(self::class .':'. $this->_file .':'. dechex(filemtime($this->_file)), function(){
			$this->_parse();

			return $this;
		});

		// Copy the object properties as we cannot overwrite $this
		foreach ($cache as $key => $value)
			$this->$key = $value;

		Basic::$log->end();
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