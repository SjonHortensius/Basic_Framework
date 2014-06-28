<?php

class Basic_DatabaseQuery extends PDOStatement
{
	protected function __construct()
	{
		$this->setFetchMode(PDO::FETCH_ASSOC);
	}

	public function totalRowCount()
	{
		if (false === strpos($this->queryString, 'SQL_CALC_FOUND_ROWS'))
			throw new Basic_DatabaseQuery_CouldNotDetermineTotalRowCountException('Missing flag `SQL_CALC_FOUND_ROWS` in your query');

		Basic::$log->start();

		$count = Basic::$database->query("SELECT FOUND_ROWS()")->fetchColumn();

		Basic::$log->end($count);

		return $count;
	}

	public function fetchArray($column = NULL, $_key = null)
	{
		if (!isset($column) && !isset($_key))
			throw new Basic_DatabaseQuery_MissingParametersException('');

		$rows = array();
		while ($row = $this->fetch())
		{
			$key = isset($_key) ? $row[ $_key ] : count($rows);

			if (isset($column))
				$rows[ $key ] = $row[ $column ];
			else
				$rows[ $key ] = $row;
		}

		return $rows;
	}

	public function execute($parameters = array())
	{
		Basic_Log::$queryCount++;

		Basic::$log->start();

		foreach ($parameters as &$parameter)
			if ($parameter instanceof Basic_Entity)
				$parameter = $parameter->_id;

		try
		{
			$result = parent::execute($parameters);
		}
		catch (PDOException $e)
		{
			Basic::$log->end('[ERROR] '. $this->queryString);

			throw new Basic_DatabaseQueryException('Database-error: %s', array($e->errorInfo[2]), 0, $e);
		}

		Basic::$log->end($this->queryString);

		return $result;
	}

	public static function escapeLike($like, $enclose = false)
	{
		return ($enclose ? '%' : ''). str_replace(array('%', '_'), array('\%', '\_'), $like). ($enclose ? '%' : '');
	}

	public function show()
	{
		$body = $header = '';

		while ($row = $this->fetch())
		{
			if (empty($header))
				$header = '<th>'. implode('</th><th>', array_keys($row)) .'</th>';

			$body .= '<tr><td>'. implode('</td><td>', $row) .'</td></tr>';
		}

		return <<<EOF
<table width="100%" border="1" cellspacing="0" cellpadding="5" class="Basic_DatabaseQuery::show">
	<caption>{$this->queryString}</caption>
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