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

		Basic::$log->write('Populated FOUND_ROWS');

		return Basic::$database->query("SELECT FOUND_ROWS()")->fetchColumn();
	}

	public function fetchAll($column = NULL, $_key = null)
	{
		if (!isset($column, $_key))
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
		Basic::$log->start();

		foreach ($parameters as $idx => $value)
		{
			if (is_bool($value))
				$type = PDO::PARAM_BOOL;
			elseif (is_null($value))
				$type = PDO::PARAM_NULL;
			elseif (is_int($value))
				$type = PDO::PARAM_INT;
			else
				$type = PDO::PARAM_STR;

			$this->bindValue(1+$idx, $value, $type);
		}

		$result = parent::execute();

		if (false === $result)
		{
			$error = $this->errorInfo();
			throw new Basic_DatabaseQuery_Exception('%s', array($error[2]), $this->errorCode());
		}

		Basic::$log->end(preg_replace('~[\s|\n|\t| ]+~', ' ', '['. $this->rowCount() .'] '. $this->queryString));
	}
}