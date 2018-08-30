<?php

class Basic_PhpException extends Basic_Exception
{
	public static function fromError($number, $message, $file, $line): void
	{
		throw new Basic_PhpException('%s in `%s`:%s', [$message, $file, $line]);
	}

	public function __toString()
	{
		// Log this error if it is not `@surpressed` - then anonymize it
		if (Basic::$config->PRODUCTION_MODE)
		{
			if (error_reporting() != 0)
				error_log($this->message ."\n". Basic::$log->getSimpleTrace($this));

			$this->message = 'An unexpected error has occurred, please contact the webmaster';
		}

		return parent::__toString();
	}
}