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

	/**
	 * Retrieve a subset of the current set - allows specifying additional filters
	 *
	 * @param string|null $filter Additional Sql filter to apply
	 * @param array $parameters Indexed list of parameters for the filter
	 * @return Basic_EntitySet
	 */
	public function getSubset(string $filter = null, array $parameters = []): self
	{
		$set = clone $this;

		if (!isset($filter))
			return $set;

		array_push($set->_filters, $filter);
		$set->_parameters = array_merge($set->_parameters, $parameters);

		return $set;
	}

	/**
	 * Change the order in which this EntitySet will be returned. Parameter should specify pairs of column-name => bool,
	 * with TRUE meaning ascending
	 *
	 * @param array $order Associative array of columns to order the Set by
	 * @return Basic_EntitySet
	 */
	public function setOrder(array $order): self
	{
		$this->_order = $order;

		return $this;
	}

	/**
	 * Retrieve intermediate Entityset based on relation. Eg. allows retrieving Users based on a SessionSet,
	 * provided each session has a relation to a single user.
	 *
	 * @param string $entityType EntityType of Entity to retrieve
	 * @param string $condition Join condition, eg. User.id = Session.user @see Basic_EntitySet::addJoin()
	 * @param null|string $alias Join table alias @see Basic_EntitySet::addJoin()
	 * @param string $type Join type @see Basic_EntitySet::addJoin()
	 * @param bool $return Join @see Basic_EntitySet::addJoin()
	 * @return Basic_EntitySet
	 */
	public function getSuperset(string $entityType, string $condition, ?string $alias = null, string $type = 'INNER', bool $return = true): self
	{
		$setClass = $entityType.'Set';
		if (!class_exists($setClass))
			eval("class $setClass extends Basic_EntitySet {}");
		/** @var $set Basic_EntitySet */
		$set = new $setClass($entityType);

		$set->_filters = $this->_filters;
		$set->_parameters = $this->_parameters;
		$set->_order = $this->_order;

		// Include original EntityType and prepend condition as first join
		$set->addJoin($this->_entityType, $condition, $alias, $type, $return);

		$set->_joins += $this->_joins;

		return $set;
	}

	/**
	 * For paginating output - return a subset based on index and size
	 *
	 * @param int $page Pagenumber, determines amount of rows to skip
	 * @param int $size Size of the page, determines number of rows returned
	 * @return Basic_EntitySet
	 */
	public function getPage(int $page, int $size): self
	{
		if ($page < 1)
			throw new Basic_EntitySet_PageNumberTooLowException('Cannot retrieve pagenumber lower than `1`');

		$set = clone $this;
		$set->_page = $page - 1;
		$set->_pageSize = $size;

		return $set;
	}

	/**
	 * Aggregate a set based on specific fields, defaults to COUNT(*)
	 *
	 * @param string $fields Sql: list of fields to return, eg. COUNT(DISTINCT name), AVERAGE(creation)
	 * @param string|null $groupBy Sql: columns to group by
	 * @param array $order Associative array of columns to order the Set by
	 * @return Basic_DatabaseQuery
	 */
	public function getAggregate(string $fields = "COUNT(*)", string $groupBy = null, array $order = []): Basic_DatabaseQuery
	{
		$set = clone $this;
		$set->_order = $order;

		return $set->_query($fields, $groupBy);
	}

	public function getIterator(string $fields = "*"): Generator
	{
		$result = $this->_query($fields);

		// This is the quick route for results from a single table
		if (empty($this->_joins))
		{
			$result->setFetchMode(PDO::FETCH_CLASS, $this->_entityType);

			while ($entity = $result->fetch())
				yield $entity->id => $entity;

			return;
		}

		$related = [];
		foreach ($this->_joins as $alias => $join)
			if ($join['return'])
				$related[$alias] = ['table' => $join['table'], 'entity' => $join['entity'], 'data' => []];

		// Create a mapper so fetch() does less processing
		$mapper = new stdClass;
		$table = $this->_entityType::getTable();
		$data = [];

		for ($i=0; $i<$result->columnCount(); $i++)
		{
			$meta = $result->getColumnMeta($i);

			if (!isset($meta['table']) || $meta['table'] == $table)
				$mapper->{$meta['name']} =& $data[$meta['name']];
			else
			{
				if (!isset($alias, $join) || $join['table'] != $meta['table'])
				{
					unset($alias, $join);
					foreach ($related as $alias => $join)
						if ($join['table'] == $meta['table'])
							break;
				}

				// Map unknown columns to top object
				if (!isset($alias, $join))
					$mapper->{$meta['name']} =& $data[ $meta['name'] ];
				else
					$mapper->{$meta['name']} =& $related[$alias]['data'][ $meta['name'] ];
			}
		}

		$result->setFetchMode(PDO::FETCH_INTO, $mapper);

		while ($result->fetch())
		{
			// data is filled through mapper, now append relations
			$entity = $this->_entityType::getStub($data);

			foreach ($related as $alias => ['entity' => $relatedEntity, 'data' => $relatedData])
				$entity->$alias = $relatedEntity::getStub($relatedData);

			yield $entity->id => $entity;
		}
	}

	protected function _query(string $fields, string $groupBy = null): Basic_DatabaseQuery
	{
		$paginate = isset($this->_pageSize, $this->_page);
		$query = "SELECT ";

		if ($paginate && 'mysql' == Basic::$database->getAttribute(PDO::ATTR_DRIVER_NAME))
			$query .= "SQL_CALC_FOUND_ROWS ";

		if (!empty($this->_joins) && $fields == "*")
		{
			$fields = [Basic_Database::escapeTable($this->_entityType::getTable()) .".*"];

			foreach ($this->_joins as $alias => $join)
				if ($join['return'])
					$fields [] = Basic_Database::escapeTable($alias) .".*";

			$fields = implode(', ', $fields);
		}

		$query .= $fields ." FROM ". Basic_Database::escapeTable($this->_entityType::getTable());

		foreach ($this->_joins as $alias => $join)
			$query .= sprintf("\n%s JOIN %s %s ON (%s)",$join['type'], Basic_Database::escapeTable($join['table']), Basic_Database::escapeTable($alias), $join['condition']);

		if (!empty($this->_filters))
			$query .= (!empty($this->_joins) ? "\n":' ')."WHERE ". implode(" AND ", $this->_filters);

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

	/**
	 * Fetch an entire Set as array (through a Generator). Supports defining a property to use as key / value
	 *
	 * @param string $property Property to use as value, pass NULL to get complete Entity
	 * @param null|string $key Property to use as key, pass NULL (with $property NON-NULL) to get numeric offset
	 * @return Generator
	 */
	public function getSimpleList(string $property = 'name', ?string $key = 'id'): Generator
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

	/**
	 * Return single Entity but throw Exception if there are zero or >1 rows
	 *
	 * @return Basic_Entity
	 */
	public function getSingle(): Basic_Entity
	{
		$iterator = $this->getIterator();
		$entity = $iterator->current();

		if (!$iterator->valid())
			throw new Basic_EntitySet_NoSingleResultException('There are `%s` results', ['0'], 404);

		$iterator->next();
		if ($iterator->valid())
			throw new Basic_EntitySet_NoSingleResultException('There are `%s` results', ['>1']);

		return $entity;
	}

	/**
	 * Join an additional Entity to this Set
	 *
	 * @param string $entityType Class-name of Entity to join
	 * @param string $condition Join condition, eg. User.id = Session.user
	 * @param null|string $alias Alias of Joined table, defaults to table-name; useful when joining same table multiple times
	 * @param string $type Type of join to perform; LEFT / RIGHT / INNER / FULL OUTER
	 * @param bool $return Whether or not to include the fields from the Entity in the resultset
	 * @return Basic_EntitySet
	 */
	public function addJoin(string $entityType, string $condition, ?string $alias = null, string $type = 'INNER', bool $return = true): self
	{
		$table = $entityType::getTable();

		if (isset($this->_joins[ $alias ?? $table ]))
			throw new Basic_EntitySet_JoinAlreadyExistsException('Cannot add join, choose a unique alias');

		$this->_joins[ $alias ?? $table ] = [
			'entity' => $entityType,
			'table' => $table,
			'condition' => $condition,
			'type' => strtoupper($type),
			'return' => $return,
		];

		return $this;
	}

	/**
	 * Magic caller, all non-existing calls are proxied to each Entity of the Set. Allows Set->delete() calls
	 *
	 * @param string $method
	 * @param array $parameters
	 */
	public function __call($method, $parameters): void
	{
		foreach ($this as $entity)
			call_user_func_array([$entity, $method], $parameters);
	}

	public function __clone()
	{
		unset($this->_pageSize, $this->_page, $this->_foundRows);
	}

	/**
	 * Retrieve COUNT from database. Uses SQL_CALC_FOUND_ROWS for mysql, or getAggregate otherwise
	 *
	 * @param bool $forceExplicit Force usage of getAggregate
	 * @return int
	 */
	public function count($forceExplicit = false): int
	{
		if (isset($this->_foundRows) && !$forceExplicit)
			return $this->_foundRows;

		return $this->getAggregate()->fetchColumn();
	}
}