<?php

class Basic_Static
{
	public static function cssResolveImports($file)
	{
		$content = file_get_contents($file);

		while (preg_match_all('~@import url\(([\'"]?)(.*?\.css)\1\);~', $content, $imports, PREG_SET_ORDER ))
		{
			foreach ($imports as $import)
				$content = str_replace($import[0], file_get_contents(dirname($file) .'/../'. $import[2]), $content);
		}

		return $content;
	}

	public static function cssStrip($output)
	{
		// strip comments
		$output = preg_replace('~/\*.*?\*/~s', '', $output);
		// strip whitespace
		$output = trim(preg_replace('~\s*([(:,;{}+(]+|([)]+[^\s]))\s*~s', '$1', $output), "\n");

		// squeeze last bytes
		$output = str_replace(';}', '}', $output);
		$output = str_replace(' !important', '!important', $output);
		$output = str_replace('  ', ' ', $output);

		// convert rgb() colors to #...
		$output = preg_replace_callback('/rgb\(([0-2]?[0-9]{0,2}),([0-2]?[0-9]{0,2}),([0-2]?[0-9]{0,2})\)/i', function($r){return '#'.dechex($r[1]).dechex($r[2]).dechex($r[3]);}, $output);
		// convert colorformat aabbcc to abc
		$output = preg_replace('/#([a-f0-9])\1([a-f0-9])\2([a-f0-9])\3/i', '#$1$2$3', $output);

		return $output;
	}

	public static function prefixCss3($css)
	{
		// properties
		$css = preg_replace('~([;{])((?:transition|transform|animation|border-radius|box-shadow)[^:]*):([^;}]+)(?=[;}])~s', '\1-webkit-\2:\3;-moz-\2:\3;-o-\2:\3;-html-\2:\3', $css);

		// values
		//$css = preg_replace('~([: ])(linear-gradient\(.+?\))~s', '\1-webkit-\2 -moz-\2 -o-\2 -html-\2', $css);

		return $css;
	}

	public static function findFiles($path, $extension, $sorted = true)
	{
		$files = iterator_to_array(new RegexIterator(
			new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
			),
			'~\.'.$extension.'$~'
		));

		if (!$sorted)
			return $files;

		uksort($files, function($a, $b){ return substr_count($a, '/') - substr_count($b, '/');});

		return $files;
	}

	public static function jsPopulateFiles($files, $minify = false)
	{
		if ($files instanceof Iterator)
			$files = array_keys(iterator_to_array($files));

		$output = new Basic_Static_JsOrder;
		foreach ($files as $path)
		{
			$content = file_get_contents($path);

			if ($minify && !class_exists('JSMinPlus'))
				require('/srv/http/.common/jsminplus.php');

			if ($minify)
				$content = JSMinPlus::minify($content).';';

			$output->insert($content);
		}

		return implode('', iterator_to_array($output));
	}
}

class Basic_Static_JsOrder extends SplMaxHeap
{
	protected $_cache = array();

	protected function _parseSource($source)
	{
		$c = crc32($source);
		$this->_cache[ $c ] = array();

		if (preg_match_all('~([a-z0-9.]+) ?= ?new Class\(~is', $source, $m))
			$this->_cache[ $c ]['class'] = $m[1][0];
		else
			return array();

		if (preg_match_all('~Extends\s?:\s?([a-z0-9.]+),~is', $source, $m))
			$this->_cache[ $c ]['extends'] = $m[1][0];

		return $this->_cache[ $c ];
	}

	function compare($s1, $s2)
	{
		if (!isset($this->_cache[ crc32($s1) ]))
			$this->_parseSource($s1);
		if (!isset($this->_cache[ crc32($s2) ]))
			$this->_parseSource($s2);

		$p1 = $this->_cache[ crc32($s1) ];
		$p2 = $this->_cache[ crc32($s2) ];

		if (!isset($p1['class'], $p2['class']))
			return 0;
		if ($p1['class'] == $p2['extends'])
			return +1;
		if ($p2['class'] == $p1['extends'])
			return -1;
		if ($p1['class'] == substr($p2['class'], 0, strlen($p1['class'])))
			return +1;
		if ($p2['class'] == substr($p1['class'], 0, strlen($p2['class'])))
			return -1;

		return strlen($p2['class']) - strlen($p1['class']);
	}
}