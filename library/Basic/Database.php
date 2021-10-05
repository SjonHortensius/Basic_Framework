<?php

class Basic_Database extends PDO
{
	public function __construct()
	{
		Basic::$log->start();

		$options = [];
		foreach (Basic::$config->Database?->options ?? [] as $key => $value)
		{
			if (is_numeric($key))
				$key = intval($key);

			$options[ $key ] = $value;
		}

		parent::__construct(Basic::$config->Database->dsn, Basic::$config->Database->username, Basic::$config->Database->password, $options);

		foreach (Basic::$config->Database?->attributes ?? [] as $key => $value)
			$this->setAttribute($key, $value);

		$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['Basic_DatabaseQuery']);
		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

		Basic::$log->end(Basic::$config->Database->dsn);
	}

	/**
	 * Combine prepare & execute providing a single method to pass both the query and parameters
	 *
	 * @param string $query Sql query to execute
	 * @param array $parameters Sql parameters for the query
	 * @return Basic_DatabaseQuery
	 */
	public function q(string $query, array $parameters = []): Basic_DatabaseQuery
	{
		try
		{
			$statement = $this->prepare($query);

			if (!$statement->execute($parameters))
				throw new Basic_DatabaseQueryException("Unexpected error from database", [], 500,
					new Basic_DatabaseQueryException("While executing: %s", [$query], 500, $e)
				);
		}
		catch (PDOException $e)
		{
			throw new Basic_DatabaseQueryException("Unexpected error `%d` from database", [$e->getCode()], 500,
				new Basic_DatabaseQueryException("While executing: %s", [$query], 500, $e)
			);
		}

		return $statement;
	}

	/**
	 * Escape a (user-provided) string for use in Sql-like; escapes '%' and '_' which are special in a like
	 *
	 * @param string $like Input to escape
	 * @param bool $enclose Whether or not to wrap the returned value in '%' characters
	 * @return string
	 */
	public static function escapeLike(string $like, bool $enclose = false): string
	{
		return ($enclose ? '%' : ''). str_replace(['%', '_'], ['\%', '\_'], $like). ($enclose ? '%' : '');
	}

	/**
	 * Escape a table-name across supported Sql backends
	 *
	 * @param string $name Table name to escape
	 * @return string
	 */
	public static function escapeTable(string $name): string
	{
		switch (Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME))
		{
			case 'pgsql':	return '"'. $name .'"';
			case 'mysql':	return '`'. $name .'`';
			default:		throw new Basic_Exception("Unsupported PDO driver %s", [Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME)]);
		}
	}

	/**
	 * Escape a column-name across supported Sql backends
	 *
	 * @param string $name Column name to escape
	 * @return string
	 */
	public static function escapeColumn(string $name): string
	{
		switch (Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME))
		{
			case 'pgsql':	return '"'. $name .'"';
			case 'mysql':	return '`'. $name .'`';
			default:		throw new Basic_Exception("Unsupported PDO driver %s", [Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME)]);
		}
	}
}