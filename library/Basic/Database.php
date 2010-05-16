<?php

class Basic_Database
{
	private $_config;
	private $_queryLog = array();
	private $_queryCount = array();
	private $_link;
	private $_queryResult;

	public $queryTotalRows;

	var $_explains = '';

	public function __construct()
	{
		$this->_config = Basic::$config->Database;
	}

	private function _connect()
	{
		Basic::$log->start();

		if ($this->_config->persistentConnect)
			$this->_link = mysql_pconnect($this->_config->host, $this->_config->username, $this->_config->password);
		else
			$this->_link = mysql_connect($this->_config->host, $this->_config->username, $this->_config->password);

		if (false == $this->_link)
			throw new Basic_Database_CouldNotConnectException('Could not connect `%s`', array(mysql_error(), array(), mysql_errno()));

		if (false == mysql_select_db($this->_config->database))
			throw new Basic_Database_CouldNotSelectDatabaseException('Could not select database `%s`', array($this->_config->database));

		Basic::$log->end($this->_config->database);

		return TRUE;
	}

	public function query($query)
	{
		if (!isset($this->_link))
			$this->_connect();

		Basic::$log->start();

		$queryType = array_shift(preg_split('/\s+/', trim($query)));
		$this->_queryResult = mysql_query($query, $this->_link);

		if (false == $this->_queryResult)
			throw new Basic_Database_QueryException(mysql_error($this->_link), array(), mysql_errno($this->_link));

		// Store the number of affected/returned rows
		if (in_array($queryType, array('SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN')))
			$result = mysql_num_rows($this->_queryResult);
		elseif (mysql_affected_rows() > -1)
			$result = mysql_affected_rows();
		else
			$result = $this->_queryResult;

		if (false !== strpos($query, 'SQL_CALC_FOUND_ROWS'))
		{
			Basic::$log->write('Populated FOUND_ROWS');
			$this->queryTotalRows = array_pop(mysql_fetch_assoc(mysql_query("SELECT FOUND_ROWS();")));
		}

		Basic::$log->end($result .' > '. preg_replace('~[\s|\n|\t| ]+~', ' ', $query));

		if (!Basic::$config->PRODUCTION_MODE && 'SELECT' == $queryType)
			$this->_explains .= $this->explain($query);

		return $result;
	}

	public function fetchNext($column = NULL)
	{
		if (!is_resource($this->_queryResult))
			return FALSE;

		$row = mysql_fetch_assoc($this->_queryResult);

		if (isset($column))
			return $row[ $column ];
		else
			return $row;
	}

	public function fetchAll($column = NULL, $_key = null)
	{
		if (!is_resource($this->_queryResult))
			return FALSE;

		$rows = array();

		while($row = mysql_fetch_assoc($this->_queryResult))
		{
			$key = isset($_key) ? $row[ $_key ] : count($rows);

			if (isset($column))
				$rows[$key] = $row[ $column ];
			else
				$rows[$key] = $row;
		}

		$this->_clean();

		return $rows;
	}

	public function fetchNextObject($className = null)
	{
		if (!is_resource($this->_queryResult))
			return FALSE;

		$class = new $className;
		$class->_load($this->fetchNext());

		return $class;
	}

	public function fetchAllObjects($className = null)
	{
		if (!is_resource($this->_queryResult))
			return FALSE;

		$results = new Basic_ModelSet;
		while ($row = $this->fetchNext())
		{
			$class = new $className;
			$class->_load($row);
			$results[ $class->id ] = $class;
		}

		$this->_clean();

		return $results;
	}

	private function _clean()
	{
		if (isset($this->_queryResult) && is_resource($this->_queryResult))
			mysql_free_result($this->_queryResult);

		unset($this->_queryResult, $this->queryTotalRows);
	}

	public function explain($query)
	{
		return $this->show(mysql_query('EXPLAIN '. $query), $query);
	}

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
}