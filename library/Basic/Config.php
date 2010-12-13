<?php

class Basic_Config
{
	protected $_file;
	protected $_modified;

	public function __construct($file = null)
	{
		$this->_file = ifsetor($file, APPLICATION_PATH .'/config.ini');
		$this->_modified = filemtime($this->_file);

		$this->_parse();
	}

	protected function _parse()
	{
		$pointer =& $this;

		foreach (explode("\n", file_get_contents($this->_file)) as $line)
		{
			// block-header
			if ('[' == $line{0} && ']' == substr($line, -1))
			{
				$pointer =& $this;

				foreach (explode(':', substr($line, 1, -1)) as $part)
					$pointer =& $pointer->$part;
			}
			// comment
			elseif (';' == $line{0})
				continue;
			// key=value
			elseif (preg_match('~(.*?)\s*=\s*(["\']?)(.*)\2~', $line, $match))
			{
				$_pointer =& $pointer;
				list(, $key, $quote, $value) = $match;

				// the value is enclosed in quotes, don't parse it
				if ('' == $quote)
				{
					if (strlen($value) > 0 && 0 === strcspn($value, '123457890'))
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
						$pointer = array();

					array_push($pointer, $value);
				}
				else
					$pointer->$part = $value;

				$pointer =& $_pointer;
			}
			elseif (!empty($line))
				throw new Basic_Config_CouldNotParseLineException('Could not parse line `%s`', array($line));
		}
	}

	public function __wakeup()
	{
 		if ($this->_modified != filemtime($this->_file))
 			throw new Basic_StaleCacheException('Internal error: the cache is stale');
	}
}