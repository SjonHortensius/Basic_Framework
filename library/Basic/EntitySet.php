<?php

class Basic_EntitySet implements ArrayAccess, Iterator, Countable
{
	protected $_entityType;
	protected $_filters = array();
	protected $_parameters = array();
	protected $_order;
	protected $_limit;

	public function __construct($entityType, $filter = null, array $parameters = array(), $order = null, $limit = null)
	{
		$this->_entityType = $entityType;

		if (isset($filter))
			$this->addFilter($filter, $parameters);

		if (isset($order))
			$this->setOrder($order);

		if (isset($offset, $limit))
			$this->setLimit($offset, $count);
	}

	public function addFilter($filter, array $parameters = array())
	{
		if (isset($this->_set))
			unset($this->_set);

		array_push($this->_filters, $filter);
		$this->_parameters = array_merge($this->_parameters, $parameters);
	}

	public function setOrder($order)
	{
		if (isset($this->_set))
			unset($this->_set);

		$this->_order = $order;
	}

	public function setLimit($offset, $count)
	{
		if (isset($this->_set))
			unset($this->_set);

		$this->_limit = $offset .(isset($count) ? ",". $count : "");
	}

	// protected since it exposes possible protected variables
	protected function __get($variable)
	{
		if ('_set' == $variable)
			$this->_fetchSet();

		return $this->$variable;
	}

	protected function _fetchSet()
	{
		$entity = new $this->_entityType;

		$query = "SELECT * FROM `". $entity->getTable() ."`";

		if (!empty($this->_filters))
			$query .= " WHERE ". implode(" AND ", $this->_filters);

		if (isset($this->_order))
			$query .= " ORDER BY ". $this->_order;

		if (isset($this->_limit))
			$query .= " LIMIT ". $this->_limit;

		$query = Basic::$database->query($query, $this->_parameters);

		$this->_set = array();

		foreach ($query->fetchAll() as $row)
		{
			$entity = new $this->_entityType;
			$entity->_load($row);

			$this->_set[ $entity->id ] = $entity;
		}
	}

	public function getSimpleList($property = 'name', $key = 'id')
	{
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

    public function count(){	return count($this->_set);	}
}
