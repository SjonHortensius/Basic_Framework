<?php

class Basic_Config
{
	protected $_file;

	public function __construct($file = null)
	{
		Basic::$log->start();

		$this->_file = $file ?? APPLICATION_PATH .'/config.ini';

		$cache = Basic::$cache->get(__CLASS__ .'::'. $this->_file .':'. filemtime($this->_file), function(){
			$this->_parse();

			return $this;
		});

		// Copy the object properties as we cannot overwrite $this
		foreach ($cache as $key => $value)
			$this->$key = $value;

		Basic::$log->end();
	}

	protected function _parse()
	{
		$pointer =& $this;

		foreach (explode("\n", file_get_contents($this->_file)) as $line)
		{
			// comment
			if (';' == $line{0})
				continue;
			// block-header
			elseif ('[' == $line{0} && ']' == substr($line, -1))
			{
				$pointer =& $this;

				foreach (explode('.', substr($line, 1, -1)) as $part)
					$pointer =& $pointer->$part;
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
					elseif (in_array($value, array('true', 'false')))
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
						$pointer =& $pointer->$part;
				}

				// support for key[]
				if ($part == '')
				{
					// This actually checks / sets the pointer target, not the pointer
					if (!isset($pointer))
						$pointer = [];

					array_push($pointer, $value);
				}
				else
@					$pointer->$part = $value;

				$pointer =& $_pointer;
			}
			elseif (!empty($line))
				throw new Basic_Config_CouldNotParseLineException('Could not parse line `%s`', array($line));
		}
	}
}