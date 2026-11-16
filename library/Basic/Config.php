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

	private function __construct(?string $file = null)
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
		// prevent Warning: unexpected NAN value was coerced to string
		$consts = array_filter(get_defined_constants(), function($v) { return is_string($v) || is_int($v) || (is_float($v) && is_finite($v));});

		foreach (parse_ini_file($this->_file, true, INI_SCANNER_TYPED) as $section => $values)
		{
			// keys before any section header
			if (!is_array($values))
			{
				$this->$section = $values;
				continue;
			}

			// [Section.Sub] -> nested stdClass
			$target = $this;
			foreach (explode('.', $section) as $part)
				$target = $target->$part ??= new stdClass;

			foreach ($values as $key => $value)
				// json_decode(json_encode()) converts assoc arrays to objects
				$target->$key = is_array($value) ? json_decode(json_encode($value)) : str_replace(array_keys($consts), $consts, $value);
		}
	}
}