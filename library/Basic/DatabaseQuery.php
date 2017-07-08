<?php

class Basic_DatabaseQuery extends PDOStatement
{
	protected function __construct()
	{
		$this->setFetchMode(PDO::FETCH_ASSOC);
	}

	public function fetchArray($column = NULL, $_key = null)
	{
		$i = 0;

		while ($row = $this->fetch())
			yield (isset($_key) ? $row[$_key] : $i++) => (isset($column) ?$row[$column] : $row);
	}

	public function execute($parameters = [], $binds = [])
	{
		Basic_Log::$queryCount++;

		Basic::$log->start();

		foreach ($parameters as &$parameter)
			if ($parameter instanceof Basic_Entity)
				$parameter = $parameter->id;

		foreach ($binds as $idx => $type)
			$this->bindParam($idx, $parameters[$idx], $type);

		try
		{
			if (!empty($binds))
				$result = parent::execute();
			else
				$result = parent::execute($parameters);
		}
		catch (PDOException $e)
		{
			Basic::$log->end('[ERROR] '. $this->queryString);

			throw new Basic_DatabaseQueryException("While executing: %s", [$this->queryString], 500, $e);
		}

		Basic::$log->end($this->queryString);

		return $result;
	}

	public function show()
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