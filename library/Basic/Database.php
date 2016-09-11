<?php

class Basic_Database extends PDO
{
	public function __construct()
	{
		Basic::$log->start();

		$options = [];
		foreach ((array)Basic::$config->Database->options as $key => $value)
		{
			if (is_numeric($key))
				$key = intval($key);

			$options[ $key ] = $value;
		}

		parent::__construct(Basic::$config->Database->dsn, Basic::$config->Database->username, Basic::$config->Database->password, $options);

		foreach ((array)Basic::$config->Database->attributes as $key => $value)
			$this->setAttribute($key, $value);

		$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('Basic_DatabaseQuery'));
		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

		Basic::$log->end(Basic::$config->Database->dsn);
	}

	/** @return Basic_DatabaseQuery */
	public function query($query, array $parameters = [])
	{
		$statement = $this->prepare($query);

		$statement->execute($parameters);

		return $statement;
	}

	public static function escapeLike($like, $enclose = false)
	{
		return ($enclose ? '%' : ''). str_replace(array('%', '_'), array('\%', '\_'), $like). ($enclose ? '%' : '');
	}

	public static function escapeTable($name)
	{
		switch (Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME))
		{
			case 'pgsql':
				return '"'. $name .'"';

			case 'mysql':
				return '`'. $name .'`';

			default:
				throw new Basic_Exception("Unknown PDO driver %s", [Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME)]);
		}
	}

	public static function escapeColumn($name)
	{
		switch (Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME))
		{
			case 'pgsql':
				return '"'. $name .'"';

			case 'mysql':
				return '`'. $name .'`';

			default:
				throw new Basic_Exception("Unknown PDO driver %s", [Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME)]);
		}
	}
}