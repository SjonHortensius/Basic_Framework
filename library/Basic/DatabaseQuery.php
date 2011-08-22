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

	public function fetchAll($column = NULL, $_key = null)
	{
		if (!isset($column) && !isset($_key))
			return parent::fetchAll();

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

		foreach ($parameters as $idx => $value)
		{
			if (is_bool($value))
				$type = PDO::PARAM_BOOL;
			elseif (is_null($value))
				$type = PDO::PARAM_NULL;
			elseif (is_int($value))
				$type = PDO::PARAM_INT;
			elseif (is_string($value))
				$type = PDO::PARAM_STR;
			else
				throw new Basic_DatabaseQuery_ParameterTypeException('Parameter number `%d` has invalid type `%s`', array($idx, gettype($value)));

			$this->bindValue(1+$idx, $value, $type);
		}

		$result = parent::execute();

		if (false === $result)
		{
			Basic::$log->end('[ERROR] '. $this->queryString);

			$error = $this->errorInfo();
			throw new Basic_DatabaseQuery_Exception('%s', array($error[2]), $this->errorCode());
		}

		Basic::$log->end('['. $this->rowCount() .'] '. $this->queryString);
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