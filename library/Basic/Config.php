<?php

class Basic_Config
{
	public function __construct($file)
	{
		$pointer =& $this;

		foreach (explode("\n", file_get_contents($file)) as $line)
		{
			// block-header
			if ($line{0} == '[' && substr($line, -1) == ']')
			{
				$pointer =& $this;

				foreach (explode(':', substr($line, 1, -1)) as $part)
					$pointer =& $pointer->$part;
			}
			// key=value
			elseif (preg_match('~(.*?)\s*=\s*(["\']?)(.*)\2~', $line, $matches))
			{
				$_pointer =& $pointer;

				// Process value
				if (($matches[2]) != '')
					;
				elseif (strlen($matches[3]) > 0 && 0 === strcspn($matches[3], '123457890'))
					$matches[3] = (int)$matches[3];
				elseif (in_array($matches[3], array('true', 'false')))
					$matches[3] = ('true' === $matches[3]);

				// key is an array
				$parts = explode('[', $matches[1]);
				foreach ($parts as $idx => $part)
				{
					$part = rtrim($part, ']');

					// Skip the last part
					if (1+$idx < count($parts))
						$pointer =& $pointer->$part;
				}

				// Handle the last part; it is not a pointer-move, but an assignment
				if ($part == '')
				{
					// This actually checks the pointer target!
					if (!isset($pointer))
						$pointer = array();

					array_push($pointer, $matches[3]);
				}
				else
					$pointer->$part = $matches[3];

				$pointer =& $_pointer;
			}
			elseif (!empty($line))
				throw new Basic_Config_CouldNotParseLineException('Could not parse line `%s`', array($line));
		}
	}
}
