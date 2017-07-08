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
	protected $_foundRows;

	public function __construct(string $entityType)
	{
		$this->_entityType = $entityType;
	}

	public function getSubset(string $filter = null, array $parameters = []): self
	{
		$set = clone $this;

		if (!isset($filter))
			return $set;

		array_push($set->_filters, $filter);
		$set->_parameters = array_merge($set->_parameters, $parameters);

		return $set;
	}

	public function setOrder(array $order)
	{
		$this->_order = $order;

		return $this;
	}

	public function getSuperset(string $entityType, string $condition, $alias = null, $type = 'INNER', $return = true): self
	{
		$setClass = $entityType.'Set';
		if (!class_exists($setClass))
			eval("class $setClass extends Basic_EntitySet {}");
		$set = new $setClass($entityType);

		$set->_filters = $this->_filters;
		$set->_parameters = $this->_parameters;
		$set->_order = $this->_order;

		// Include original EntityType and prepend condition as first join
		$set->addJoin($this->_entityType, $condition, $alias, $type, $return);

		$set->_joins += $this->_joins;

		return $set;
	}

	public function getPage($page, $size): self
	{
		if ($page < 1)
			throw new Basic_EntitySet_PageNumberTooLowException('Cannot retrieve pagenumber lower than `1`');

		$set = clone $this;
		$set->_page = $page - 1;
		$set->_pageSize = $size;

		return $set;
	}

	public function getAggregate($fields = "COUNT(*)", $groupBy = null, $order = []): Basic_DatabaseQuery
	{
		$set = clone $this;
		$set->_order = $order;

		return $set->_query($fields, $groupBy);
	}

	public function getIterator($fields = "*")
	{
		$result = $this->_query($fields);
		$result->setFetchMode(PDO::FETCH_CLASS, $this->_entityType);

		while ($entity = $result->fetch())
			yield $entity->id => $entity;
	}

	protected function _query(string $fields, $groupBy = null): Basic_DatabaseQuery
	{
		$paginate = isset($this->_pageSize, $this->_page);
		$query = "SELECT ";

		if ($paginate && 'mysql' == Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME))
			$query .= "SQL_CALC_FOUND_ROWS ";

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

		try
		{
			return Basic::$database->query($query, $this->_parameters);
		}
		finally
		{
			if ($paginate && 'mysql' == Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME))
				$this->_foundRows = Basic::$database->query("SELECT FOUND_ROWS()")->fetchColumn();
		}
	}

	public function getSimpleList($property = 'name', $key = 'id'): array
	{
		$fields = Basic_Database::escapeTable($this->_entityType::getTable()) .'.'. (isset($property) ? Basic_Database::escapeColumn($property) : "*");

		if (isset($property, $key) && $key !== $property)
			$fields .= ",". Basic_Database::escapeTable($this->_entityType::getTable()) .'.'. Basic_Database::escapeColumn($key);

		$result = $this->_query($fields);

		if (isset($property))
			return yield from $result->fetchArray($property, $key);

		$result->setFetchMode(PDO::FETCH_CLASS, $this->_entityType);

		while ($entity = $result->fetch())
			yield $entity->{$key} => $entity;
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

	public function addJoin(string $entityType, string $condition, string $alias = null, string $type = 'INNER', bool $return = true): self
	{
		$table = $entityType::getTable();

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
		unset($this->_pageSize, $this->_page, $this->_foundRows);
	}

	public function count($forceExplicit = false): int
	{
		if (isset($this->_foundRows) && !$forceExplicit)
			return $this->_foundRows;

		return $this->getAggregate()->fetchColumn();
	}
}