<?php

class Basic_DatabaseQuery extends PDOStatement
{
	protected function __construct()
	{
		$this->setFetchMode(PDO::FETCH_ASSOC);
	}

	public function fetchArray(string $column = null, string $_key = null): Generator
	{
		$i = 0;

		while ($row = $this->fetch())
			yield (isset($_key) ? $row[$_key] : $i++) => (isset($column) ?$row[$column] : $row);
	}

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

			switch (gettype($parameter))
			{
				case 'boolean':	$this->bindValue($idx, $parameter, PDO::PARAM_BOOL); break;
				case 'integer':	$this->bindValue($idx, $parameter, PDO::PARAM_INT);  break;
				case 'NULL':	$this->bindValue($idx, $parameter, PDO::PARAM_NULL); break;
				case 'resource':$this->bindValue($idx, $parameter, PDO::PARAM_LOB);  break;
				case 'double':
				case 'string':	$this->bindValue($idx, $parameter, PDO::PARAM_STR);  break;

				default:
					throw new Basic_DatabaseExecuteException('Unknown parameter-type: %s', [gettype($parameter)]);
			}

		}

		try
		{
			Basic_Log::$queryCount++;
			Basic::$log->start();

			return parent::execute();
		}
		catch (PDOException $e)
		{
			throw new Basic_DatabaseQueryException("While executing: %s", [$this->queryString], 500, $e);
		}
		finally
		{
			Basic::$log->end($this->queryString);
		}
	}

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