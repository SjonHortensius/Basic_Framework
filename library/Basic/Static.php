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

	public static function cssStrip(string $output, int $inlineMax = 0): string
	{
		// strip comments
		$output = preg_replace('~/\*.*?\*/~s', '', $output);
		// strip whitespace
		$output = trim(preg_replace('~\s*([(:,;{}+(]+|([)]+[^\s]))\s*~s', '$1', $output), "\n");

		// squeeze last bytes
		$output = str_replace(';}', '}', $output);
		$output = str_replace(' !important', '!important', $output);
		for ($i=0; $i<9; $i++)
			$output = str_replace('  ', ' ', $output);

		// convert rgb() colors to #...
		$output = preg_replace_callback('/rgb\(([0-2]?[0-9]{0,2}),([0-2]?[0-9]{0,2}),([0-2]?[0-9]{0,2})\)/i', function($r){return '#'.dechex($r[1]).dechex($r[2]).dechex($r[3]);}, $output);
		// convert colorformat aabbcc to abc
		$output = preg_replace('/#([a-f0-9])\1([a-f0-9])\2([a-f0-9])\3/i', '#$1$2$3', $output);

		// Inline small images
		$output = preg_replace_callback('~url\(([^)]+)\)~', function($m) use ($inlineMax) {
			$f = $m[1];

			if ($f[0] == '/')
				$f = '..'.$f;

			if (filesize($f) < $inlineMax)
				return 'url(data:'. getimagesize($f)['mime'] .';base64,'. base64_encode(file_get_contents($f)).')';

			return $m[0];
		}, $output);

		return $output;
	}

	public static function findFiles($path, $extension, $sorted = true): array
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
}