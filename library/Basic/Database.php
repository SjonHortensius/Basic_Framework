<?php

class Basic_Database extends PDO
{
	public function __construct()
	{
		Basic::$log->start();

		parent::__construct('mysql:host='. Basic::$config->Database->host .';dbname='. Basic::$config->Database->database, Basic::$config->Database->username, Basic::$config->Database->password, array(PDO::MYSQL_ATTR_INIT_COMMAND =>  "SET NAMES 'UTF8'"));

		// This will enable rowCount() to work on all SELECT queries
		$this->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
		$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('Basic_DatabaseQuery'));

		Basic::$log->end(Basic::$config->Database->database);
	}

	public function query($query, array $parameters = array())
	{
		$statement = $this->prepare($query);

		$statement->execute($parameters);

		return $statement;
	}
}