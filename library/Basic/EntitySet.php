<?php

class Basic_EntitySet implements IteratorAggregate, Countable
{
	protected $_entityType;
	protected $_filters = array();
	protected $_parameters = array();
	protected $_order;
	protected $_pageSize;
	protected $_page;
	protected $_totalCount;
	protected $_uniqueCache = array();
	protected $_fetchedCount;

	public function __construct($entityType, $filter = null, array $parameters = array(), array $order = array())
	{
		$this->_entityType = $entityType;

		$this->_filters = isset($filter) ? array($filter) : array();
		$this->_parameters = $parameters;
		$this->_order = $order;
	}

	public function getSubset($filter = null, array $parameters = array(), $order = null)
	{
		$set = clone $this;

		if (isset($filter))
			array_push($set->_filters, $filter);

		$set->_parameters = array_merge($set->_parameters, $parameters);

		if (isset($order))
			$set->_order = $order;

		return $set;
	}

	public function getPage($page, $size)
	{
		if ($page < 1)
			throw new Basic_EntitySet_PageNumberTooLow('Cannot retrieve pagenumber lower than `1`');

		$set = clone $this;
		$set->_page = $page - 1;
		$set->_pageSize = $size;

		return $set;
	}

	public function getTotalCount()
	{
		if (!isset($this->_pageSize, $this->_page))
			throw new Basic_EntitySet_NoCountAvailableException('No count available, did you use getPage?');

		if (!isset($this->_totalCount))
		{
			$result = $this->_query();
			$this->_totalCount = $result->totalRowCount();
		}

		return $this->_totalCount;
	}

	public function getCount($groupBy = null, $quick = false)
	{
		if (!isset($groupBy) && (isset($this->_fetchedCount) || !$quick))
			return $this->_fetchedCount;

		$fields = "COUNT(*) c" . (isset($groupBy) ? ", ". $groupBy : "");
		$rows = $this->_query($fields, $groupBy)->fetchArray('c', $groupBy);

		if (!isset($groupBy))
			return (int)$rows[0];

		return $rows;
	}

	function getIterator()
	{
		$result = $this->_query();
		$result->setFetchMode(PDO::FETCH_CLASS, $this->_entityType);

		if (isset($this->_pageSize, $this->_page))
			$this->_totalCount = $result->totalRowCount();

		try
		{
			$fetchedCount = 0;
			while ($entity = $result->fetch())
			{
				$fetchedCount++;

				yield $entity->id => $entity;
			}
		}
		finally
		{
			$this->_fetchedCount = $fetchedCount;
		}
	}

	protected function _query($fields = "*", $groupBy = null)
	{
		$paginate = isset($this->_pageSize, $this->_page);

		$entityType = $this->_entityType;
		$query = "SELECT ". ($paginate ? "SQL_CALC_FOUND_ROWS " : ""). $fields ." FROM ". $entityType::getTable();
		$query = $this->_processQuery($query);

		if (!empty($this->_filters))
			$query .= " WHERE ". implode(" AND ", $this->_filters);

		if (isset($groupBy))
			$query .= " GROUP BY ". $groupBy;
		if (!empty($this->_order))
		{
			$order = array();
			foreach ($this->_order as $property => $ascending)
				array_push($order, '`'. $property. '` '. ($ascending ? "ASC" : "DESC"));

			$query .= " ORDER BY ". implode(', ', $order);
		}

		if ($paginate)
			$query .= " LIMIT ". ($this->_page * $this->_pageSize) .",". $this->_pageSize;

		return Basic::$database->query($query, $this->_parameters);
	}

	protected function _processQuery($query)
	{
		return $query;
	}

	public function getSimpleList($property = 'name', $key = 'id')
	{
		$list = array();

		foreach ($this as $entity)
			$list[ $entity->{$key} ] = isset($property) ? $entity->{$property} : $entity;

		return $list;
	}

	public function getSingle()
	{
		$iterator = $this->getIterator();
		$entity = $iterator->current();

		if (!$iterator->valid())
			throw new Basic_EntitySet_NoSingleResultException('There are `%s` results', array('0'));

		$iterator->next();
		if ($iterator->valid())
			throw new Basic_EntitySet_NoSingleResultException('There are `%s` results', array('>1'));

		return $entity;
	}

	public function __call($method, $parameters)
	{
		foreach ($this as $entity)
			call_user_func_array(array($entity, $method), $parameters);
	}

	public function __clone()
	{
		unset($this->_fetchedCount);
	}

	public function count(){	return $this->getCount(null, true);	}
}