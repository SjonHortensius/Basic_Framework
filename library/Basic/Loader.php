<?php

class Basic_Loader
{
	public static function autoLoad($className)
	{
		$parts = explode('_', $className);

		if ('Basic' == $parts[0])
			require_once(FRAMEWORK_PATH .'/library/'. implode('/', $parts) .'.php');
		elseif ('Action' == end($parts))
		{
			array_pop($parts);
			require_once(APPLICATION_PATH .'/library/'. implode('/', $parts) .'.php');
		}
		else
			require_once(APPLICATION_PATH .'/library/'. implode('/', $parts) .'.php');
	}
}