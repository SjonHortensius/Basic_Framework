<?php

class Basic_Database extends PDO
{
	private $_config;

	public function __construct()
	{
		Basic::$log->start();
		$this->_config = Basic::$config->Database;

		parent::__construct('mysql:host='. $this->_config->host .';dbname='. $this->_config->database, $this->_config->username, $this->_config->password, array(PDO::MYSQL_ATTR_INIT_COMMAND =>  "SET NAMES 'UTF8'"));

		// This will enable rowCount() to work on all SELECT queries
		$this->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
		$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('Basic_DatabaseQuery'));

		Basic::$log->end($this->_config->database);
	}

	public function query($query, $parameters = array())
	{
		$statement = $this->prepare($query);

		$statement->execute($parameters);

		return $statement;
	}
/*
	public function show($result, $query)
	{
		if (isset($result))
		{
			$_result = $this->_queryResult;
			$this->_queryResult = $result;
		}

		$output = '<table width="100%" border="1" cellspacing="0" cellpadding="5"><caption>'. htmlspecialchars($query) .'</caption><thead><tr>';
		mysql_data_seek($result, 0);
		for ($i = 0; $i < mysql_num_fields($result); $i++)
			$output .= '<th>'. mysql_field_name($result, $i) .'</th>';

		$output .= '</tr></thead><tbody>';
		while ($row = $this->fetchNext())
		{
			$output .= '<tr>';
			for ($i = 0; $i < mysql_num_fields($result); $i++)
				$output .= '<td>'. $row[mysql_field_name($result, $i)] .'</td>';
			$output .= '</tr>';
		}
		$output .= '</tbody></table>';

		if (isset($_result))
			$this->_queryResult = $_result;

		return $output;
	}

	public static function escape($string)
	{
		return mysql_escape_string(preg_replace ('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $string));
	}
*/
}