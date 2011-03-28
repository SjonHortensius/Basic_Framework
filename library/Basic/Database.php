<?php

class Basic_Database extends PDO
{
	public function __construct()
	{
		Basic::$log->start();

		parent::__construct('mysql:host='. Basic::$config->Database->host .';dbname='. Basic::$config->Database->database, Basic::$config->Database->username, Basic::$config->Database->password, array(PDO::MYSQL_ATTR_INIT_COMMAND =>  "SET NAMES 'UTF8'"));

		// This will enable rowCount() to work on all SELECT queries
		$this->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
		$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('Basic_DatabaseQuery'));
//		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		Basic::$log->end(Basic::$config->Database->database);
	}

	public function query($query, array $parameters = array())
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
*/
}