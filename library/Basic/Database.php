<?php

class Basic_Database extends PDO
{
	public function __construct()
	{
		Basic::$log->start();

		parent::__construct(Basic::$config->Database->dsn, Basic::$config->Database->username, Basic::$config->Database->password, (array)Basic::$config->Database->options);

		foreach ((array)Basic::$config->Database->attributes as $key => $value)
			$this->setAttribute($key, $value);

		$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('Basic_DatabaseQuery'));
		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		Basic::$log->end(Basic::$config->Database->database);
	}

	public function query($query, array $parameters = array())
	{
		$statement = $this->prepare($query);

		$statement->execute($parameters);

		return $statement;
	}
}