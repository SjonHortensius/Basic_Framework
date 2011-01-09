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

		if (isset($filter))
			array_push($set->_filters, $filter);

		$set->_parameters = array_merge($set->_parameters, $parameters);

		if (isset($order))
			$set->_order = $order;

		return $set;
	}

	public function getPage($page, $size)
	{
		$set = clone $this;
		$set->_page = $page;
		$set->_pageSize = $size;

		return $set;
	}

	public function getTotalCount()
	{
		// Force fetching of actual data
		if (!isset($this->_totalCount))
			$this->__get('_set');

		return $this->_totalCount;
	}

	public function getCount($groupBy = null)
	{
		if (isset($this->_set) && !isset($groupBy))
			return count($this->_set);

		$fields = "COUNT(*)" . (isset($groupBy) ? ", "+$groupBy : "");
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
			$entity->_load($row);

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
		if (!isset($this->_set))
		{
			$result = $this->_fetchSet($property +','+ $key);

			$output = array();
			foreach ($result->fetchAll() as $row)
			{
				$entity = new $this->_entityType;
				$entity->_load($row);

				$output[ $entity->{$key} ] = $entity->{$property};
			}

			return $output;
		}

		$output = array();
		foreach ($this->_set as $entity)
			$output[ $entity->{$key} ] = $entity->{$property};

		return $output;
	}

	public function getSingle()
	{
		if (count($this->_set) != 1)
			throw new Basic_EntitySet_NoSingleResultException('There are `%s` results', array(count($this->_set)));

		return current($this->_set);
	}

	public function offsetExists($offset){		return array_key_exists($offset, $this->_set);	}
	public function offsetGet($offset){			return $this->_set[ $offset ];					}
	public function offsetSet($offset, $value){	return $this->_set[ $offset ] = $value;			}
	public function offsetUnset($offset){		unset($this->_set[ $offset ]);					}

    public function rewind(){	reset($this->_set);					}
    public function current(){	return current($this->_set);		}
    public function key(){		return key($this->_set);			}
    public function next(){		return next($this->_set);			}
    public function valid(){	return false !== $this->current();	}

    public function count(){	return $this->getCount();			}
}
