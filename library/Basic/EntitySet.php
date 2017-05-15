<?php

class Basic_EntitySet implements IteratorAggregate, Countable
{
	protected $_entityType;
	protected $_filters = [];
	protected $_parameters = [];
	protected $_order;
	protected $_joins = [];
	protected $_pageSize;
	protected $_page;
	protected $_hasFoundRows;

	public function __construct($entityType, $filter = null, array $parameters = [], array $order = [])
	{
		$this->_entityType = $entityType;

		$this->_filters = isset($filter) ? [$filter] : [];
		$this->_parameters = $parameters;
		$this->_order = $order;
	}

	public function getSubset($filter = null, array $parameters = [], $order = null): Basic_EntitySet
	{
		$set = clone $this;

		if (isset($filter))
			array_push($set->_filters, $filter);

		$set->_parameters = array_merge($set->_parameters, $parameters);

		if (isset($order))
			$set->_order = $order;

		return $set;
	}

	public function getPage($page, $size): Basic_EntitySet
	{
		if ($page < 1)
			throw new Basic_EntitySet_PageNumberTooLowException('Cannot retrieve pagenumber lower than `1`');

		$set = clone $this;
		$set->_page = $page - 1;
		$set->_pageSize = $size;

		return $set;
	}

	public function getAggregate($fields = "COUNT(*)", $groupBy = null, $order = [])
	{
		$set = clone $this;
		$set->_order = $order;

		return $set->_query($fields, $groupBy);
	}

	public function getIterator()
	{
		$result = $this->_query();
		$result->setFetchMode(PDO::FETCH_CLASS, $this->_entityType);

		while ($entity = $result->fetch())
			yield $entity->id => $entity;
	}

	protected function _query($fields = "*", $groupBy = null)
	{
		$paginate = isset($this->_pageSize, $this->_page);
		$query = "SELECT ";

		if ($paginate && 'mysql' == Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME))
		{
			$query .= "SQL_CALC_FOUND_ROWS ";
			$this->_hasFoundRows = true;
		}

		if (!empty($this->_joins) && $fields == "*")
		{
			$fields = [$this->_entityType::getTable() .".*"];

			foreach ($this->_joins as $alias => $join)
				if ($join['return'])
					$fields []= $alias.".*";

			$fields = implode($fields, ', ');
		}

		$query .= $fields ." FROM ". Basic_Database::escapeTable($this->_entityType::getTable());

		foreach ($this->_joins as $alias => $join)
			$query .= "\n{$join['type']} JOIN ".Basic_Database::escapeTable($join['table'])." $alias ON ({$join['condition']})";

		$query = $this->_processQuery($query);

		if (!empty($this->_filters))
			$query .= (!empty($this->_joins) ? "\n":'')." WHERE ". implode(" AND ", $this->_filters);

		if (isset($groupBy))
			$query .= " GROUP BY ". $groupBy;
		if (!empty($this->_order))
		{
			$order = [];
			foreach ($this->_order as $property => $ascending)
				array_push($order, $property. ' '. ($ascending ? "ASC" : "DESC"));

			$query .= " ORDER BY ". implode(', ', $order);
		}

		if ($paginate)
			$query .= " LIMIT ". $this->_pageSize ." OFFSET ". ($this->_page * $this->_pageSize);

		return Basic::$database->query($query, $this->_parameters);
	}

	protected function _processQuery($query): string
	{
		return $query;
	}

	public function getSimpleList($property = 'name', $key = 'id'): array
	{
		$list = [];
		$fields = Basic_Database::escapeTable($this->_entityType::getTable()) .'.'. (isset($property) ? Basic_Database::escapeColumn($property) : "*");

		if (isset($property, $key))
			$fields .= ",". Basic_Database::escapeTable($this->_entityType::getTable()) .'.'. Basic_Database::escapeColumn($key);

		$result = $this->_query($fields);
		$result->setFetchMode(PDO::FETCH_CLASS, $this->_entityType);

		while ($entity = $result->fetch())
		{
			$list[ $entity->{$key} ] = isset($property) ? $entity->{$property} : $entity;

			if (isset($property))
				$entity->removeCached();
		}

		return $list;
	}

	public function getSingle(): Basic_Entity
	{
		$iterator = $this->getIterator();
		$entity = $iterator->current();

		if (!$iterator->valid())
			throw new Basic_EntitySet_NoSingleResultException('There are `%s` results', array('0'), 404);

		$iterator->next();
		if ($iterator->valid())
			throw new Basic_EntitySet_NoSingleResultException('There are `%s` results', array('>1'));

		return $entity;
	}

	public function addJoin($table, $condition, $alias = null, $type = 'INNER', $return = true): self
	{
		if (!isset($alias))
			$alias = $table;

		$this->_joins[ $alias ] = [
			'table' => $table,
			'condition' => $condition,
			'type' => strtoupper($type),
			'return' => $return,
		];

		return $this;
	}

	public function __call($method, $parameters): void
	{
		foreach ($this as $entity)
			call_user_func_array(array($entity, $method), $parameters);
	}

	public function __clone()
	{
		unset($this->_pageSize, $this->_page, $this->_hasFoundRows);
	}

	public function count($forceExplicit = false): int
	{
		if ($this->_hasFoundRows && !$forceExplicit)
			return Basic::$database->query("SELECT FOUND_ROWS()")->fetchColumn();

		return $this->getAggregate()->fetchColumn();
	}
}