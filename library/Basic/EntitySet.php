<?php

class Basic_EntitySet implements ArrayAccess, Iterator, Countable
{
	protected $_entityType;
	protected $_filters = array();
	protected $_parameters = array();
	protected $_order;
	protected $_pageSize;
	protected $_page;
	protected $_totalCount;

	public function __construct($entityType, $filter = null, array $parameters = array(), $order = null)
	{
		$this->_entityType = $entityType;

		$this->_filters = isset($filter) ? array($filter) : array();
		$this->_parameters = $parameters;
		$this->_order = $order;
	}

	public function getSubset($filter = null, array $parameters = array(), $order = null)
	{
		$set = clone $this;
		unset($set->_set);

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
		// Force fetching of actual data
		if (!isset($this->_totalCount))
			$this->__get('_set');

		if (!isset($this->_totalCount))
			throw new Basic_EntitySet_NoCountAvailableException('No count available, did you use getPage?');

		return $this->_totalCount;
	}

	public function getCount($groupBy = null, $quick = false)
	{
		if (!isset($groupBy) && (isset($this->_set) || !$quick))
			return count($this->_set);

		$fields = "COUNT(*)" . (isset($groupBy) ? ", ". $groupBy : "");
		$rows = $this->_fetchSet($fields, $groupBy)->fetchAll('COUNT(*)', $groupBy, true);

		if (!isset($groupBy))
			return $rows[0];

		return $rows;
	}

	public function __get($property)
	{
		if ('_set' != $property)
			throw new Basic_EntitySet_UndefinedPropertyException('Undefined property `%s`', $property);

		$result = $this->_fetchSet();

		if (isset($this->_pageSize, $this->_page))
			$this->_totalCount = $result->totalRowCount();

		$this->_set = array();

		foreach ($result->fetchAll() as $row)
		{
			$entity = new $this->_entityType;
			$entity->_load($row, $result);

			$this->_set[ $entity->id ] = $entity;
		}

		return $this->_set;
	}

	protected function _fetchSet($fields = "*", $groupBy = null)
	{
		$paginate = isset($this->_pageSize, $this->_page);

		$entity = new $this->_entityType;
		$query = "SELECT ". ($paginate ? "SQL_CALC_FOUND_ROWS " : ""). $fields ." FROM `". $entity->getTable() ."`";

		if (!empty($this->_filters))
			$query .= " WHERE ". implode(" AND ", $this->_filters);

		if (isset($groupBy))
			$query .= " GROUP BY ". $groupBy;

		if (isset($this->_order))
			$query .= " ORDER BY ". $this->_order;

		if ($paginate)
			$query .= " LIMIT ". ($this->_page * $this->_pageSize) .",". $this->_pageSize;

		return Basic::$database->query($query, $this->_parameters);
	}

	public function getSimpleList($property = 'name', $key = 'id')
	{
		$list = array();

		foreach ($this->_set as $entity)
			$list[ $entity->{$key} ] = $entity->{$property};

		return $list;
	}

	public function getSingle()
	{
		if (count($this->_set) != 1)
			throw new Basic_EntitySet_NoSingleResultException('There are `%s` results', array(count($this->_set)));

		return current($this->_set);
	}

	public function offsetExists($offset){		return array_key_exists($offset, $this->_set);	}
	public function offsetGet($offset){			return $this->_set[ $offset ];					}
	public function offsetSet($offset, $value){	throw new Basic_EntitySet_UnsupportedException;	}
	public function offsetUnset($offset){		throw new Basic_EntitySet_UnsupportedException;	}

    public function rewind(){	reset($this->_set);					}
    public function current(){	return current($this->_set);		}
    public function key(){		return key($this->_set);			}
    public function next(){		next($this->_set);			}
    public function valid(){	return false !== $this->current();	}

    public function count(){	return $this->getCount();			}
}