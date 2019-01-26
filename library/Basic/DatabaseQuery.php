<?php

class Basic_DatabaseQuery extends PDOStatement
{
	protected const PHP_TYPE_TO_PDO = [
		'boolean' => PDO::PARAM_BOOL,
		'integer' => PDO::PARAM_INT,
		'NULL' => PDO::PARAM_NULL,
		'resource' => PDO::PARAM_LOB,
		'double' => PDO::PARAM_STR,
		'string' => PDO::PARAM_STR,
	];

	protected function __construct()
	{
		$this->setFetchMode(PDO::FETCH_ASSOC);
	}

	/**
	 * Fetch an entire result set as array (through a Generator). Supports defining a column as key / value
	 *
	 * @param string|null $column Column to use as value, defaults to entire row
	 * @param string|null $_key Column to use as key, defaults to numeric offset
	 * @return Generator
	 */
	public function fetchArray(string $column = null, string $_key = null): Generator
	{
		$i = 0;

		while ($row = $this->fetch())
			yield (isset($_key) ? $row[$_key] : $i++) => (isset($column) ? $row[$column] : $row);
	}

	/**
	 * Execute query, automatically binding parameters using correct Pdo types. Adds logging
	 *
	 * @param array $parameters Indexed or associative list of parameter values
	 * @return bool
	 */
	public function execute($parameters = []): bool
	{
		$isNum = (array_keys($parameters) === range(0, count($parameters) - 1));

		foreach ($parameters as $idx => $parameter)
		{
			// Columns/Parameters are 1-based
			if ($isNum)
				$idx++;

			if ($parameter instanceof Basic_Entity)
				$parameter = $parameter->id;

			if (isset(self::PHP_TYPE_TO_PDO[gettype($parameter)]))
				$this->bindValue($idx, $parameter, self::PHP_TYPE_TO_PDO[gettype($parameter)]);
			else
				throw new Basic_DatabaseExecuteException('Unknown parameter-type: %s', [gettype($parameter)]);
		}

		try
		{
			Basic_Log::$queryCount++;
			Basic::$log->start();

			return parent::execute();
		}
		catch (PDOException $e)
		{
			throw new Basic_DatabaseQueryException("Unexpected error `%d` from database", [$e->getCode()], 500,
				new Basic_DatabaseQueryException("While executing: %s", [$this->queryString], 500, $e)
			);
		}
		finally
		{
			Basic::$log->end($this->queryString);
		}
	}

	/**
	 * For debug-purposes; return the resultset as a Html table
	 *
	 * @return string
	 */
	public function show(): string
	{
		$body = $header = '';
		$query = htmlspecialchars($this->queryString);

		while ($row = $this->fetch())
		{
			if (empty($header))
				$header = '<th>'. implode('</th><th>', array_keys($row)) .'</th>';

			$body .= '<tr><td>'. implode('</td><td>', $row) .'</td></tr>';
		}

		return <<<EOF
<table width="100%" border="1" cellspacing="0" cellpadding="5" class="Basic_DatabaseQuery::show">
	<caption style="white-space: pre">{$query}</caption>
	<thead>
		<tr>{$header}</tr>
	</thead>
	<tbody>
		{$body}
	</tbody>
</table>

EOF;
	}
}