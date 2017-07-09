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

		$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['Basic_DatabaseQuery']);
		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

		Basic::$log->end(Basic::$config->Database->dsn);
	}

	public function query(string $query, array $parameters = []): Basic_DatabaseQuery
	{
		try
		{
			$statement = $this->prepare($query);
			$statement->execute($parameters);
		}
		catch (PDOException $e)
		{
			throw new Basic_DatabaseQueryException("While executing: %s", [$query], 500, $e);
		}

		return $statement;
	}

	public static function escapeLike(string $like, bool $enclose = false): string
	{
		return ($enclose ? '%' : ''). str_replace(['%', '_'], ['\%', '\_'], $like). ($enclose ? '%' : '');
	}

	public static function escapeTable(string $name): string
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

	public static function escapeColumn(string $name): string
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