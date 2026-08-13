<?php

namespace BitApps\WPDatabase;

use BitApps\WPDatabase\Concerns\QueriesRelationships;
use BitApps\WPDatabase\Query\Grammar;
use BitApps\WPDatabase\Query\Identifier;
use BitApps\WPDatabase\Query\JoinType;
use BitApps\WPDatabase\Query\RawTemplate;
use BitApps\WPDatabase\Query\SqlOperator;

use Closure;
use DateTime;
use DateTimeZone;
use RuntimeException;

class QueryBuilder
{
    use QueriesRelationships;

    public const UPDATE = 'Update';

    public const INSERT = 'Insert';

    public const DELETE = 'Delete';

    public const SELECT = 'Select';

    public const RAW = 'Raw';

    public const TIME_FORMAT = 'Y-m-d H:i:s';

    public static $TIME_ZONE;

    public $select = [];

    public $selectRaw = [
        'columns'  => [],
        'bindings' => [],
    ];

    /**
     * Framework-generated SELECT expressions whose structural parts remain
     * typed until Grammar compilation. Developer-authored expressions belong
     * in selectRaw().
     *
     * @var array<int, array>
     */
    protected $selectExpressions = [];

    protected $table;

    protected $limit;

    protected $offset;

    protected $orderBy = [];

    protected $order;

    protected $where = [];

    protected $params = [];

    protected $joins = [];

    protected $groupBy = [];

    protected $having = [];

    protected $bindings = [];

    protected $insert = [];

    protected $update = [];

    protected $distinct = false;

    protected $columns = ['*'];

    protected $raw = '';

    private $_model;

    private $_from;

    private $_for;

    private $_method;

    private $_grammar;

    private $_withTrashed = false;

    private $_onlyTrashed = false;

    private $_forceDelete = false;

    /**
     * Constructs QueryBuilder
     *
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->_model = $model;
        $this->table  = $model->getTable();
    }

    /**
     * Do necessary when clone
     *
     * @return void
     */
    public function __clone()
    {
        $this->bindings = [];
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this->_model, $name)) {
            return $this->_model->{$name}(...$arguments);
        }

        throw new RuntimeException('Call to undefined method ' . __CLASS__ . '::' . esc_html($name) . '()');
    }

    /**
     * Clone this class with all conditions
     */
    public function clone()
    {
        return clone $this;
    }

    /**
     * Adds casts to the bound model at runtime, keeping the builder chainable.
     *
     * @param array $casts
     *
     * @return $this
     */
    public function withCast(array $casts)
    {
        $this->_model->withCast($casts);

        return $this;
    }

    /**
     * Sets alias for table
     *
     * @param string $_from
     *
     * @return self
     */
    public function from($_from)
    {
        if (!\is_string($_from)) {
            throw new RuntimeException('Invalid SQL table alias.');
        }

        Identifier::assertSimple($_from);
        $this->_from = $_from;

        return $this;
    }

    /**
     * Returns model for current query
     *
     * @return Model
     */
    public function getModel()
    {
        return $this->_model;
    }

    /**
     * Undocumented function
     *
     * @param string $_for
     *
     * @return self
     */
    public function queryFor($_for)
    {
        $this->_for = $_for;

        return $this;
    }

    /**
     * Returns new instance
     *
     * @return self
     */
    public function newQuery()
    {
        return new QueryBuilder($this->_model);
    }

    /**
     * Returns the SQL grammar used to compile select statements.
     *
     * @return Grammar
     */
    public function grammar()
    {
        return $this->_grammar ??= new Grammar();
    }

    /**
     * Add bindings for this query
     *
     * @param array $bindings
     *
     * @return void
     */
    public function addBindings($bindings)
    {
        if (\is_null($bindings)) {
            return;
        }

        if (!\is_array($bindings)) {
            $bindings = [$bindings];
        }

        $this->bindings = array_merge($this->bindings, $bindings);
    }

    /**
     * Returns bindings for this query
     *
     * @return array
     */
    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * Resets the bindings collected for this query.
     *
     * @return void
     */
    public function resetBindings()
    {
        $this->bindings = [];
    }

    /**
     * Returns the (prefixed) table name for this query.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * Returns the clause list (where/having) for the given type.
     *
     * When the type is 'where' and the model opts into soft-delete scope,
     * a deleted_at IS NULL (or IS NOT NULL for onlyTrashed) clause is injected
     * on SELECT queries without mutating $this->where.
     *
     * @param string $type
     *
     * @return array
     */
    public function getClauseList($type)
    {
        if ($type === 'having') {
            return $this->having;
        }

        if (!$this->isSoftDeleteModel() || $this->_method !== self::SELECT) {
            return $this->where;
        }

        $scopeClause = null;
        if ($this->_onlyTrashed) {
            $scopeClause = ['column' => 'deleted_at', 'operator' => 'IS NOT NULL'];
        } elseif ($this->autoScopeEnabled() && !$this->_withTrashed) {
            $scopeClause = ['column' => 'deleted_at', 'operator' => 'IS NULL'];
        }

        if ($scopeClause === null) {
            return $this->where;
        }

        if (empty($this->where)) {
            return [$scopeClause];
        }

        // Wrap user conditions to prevent AND/OR precedence issues with the injected scope
        $nestedQuery        = $this->newNestedQuery();
        $nestedQuery->where = $this->where;

        return [
            ['query' => $nestedQuery],
            $scopeClause,
        ];
    }

    /**
     * Returns the join definitions for this query.
     *
     * @return array
     */
    public function getJoins()
    {
        return $this->joins;
    }

    /**
     * Returns the group by columns for this query.
     *
     * @return array
     */
    public function getGroupByList()
    {
        return $this->groupBy;
    }

    /**
     * Returns the order by definitions for this query.
     *
     * @return array
     */
    public function getOrderByList()
    {
        return $this->orderBy;
    }

    /**
     * Returns the limit value for this query.
     *
     * @return int|string|null
     */
    public function getLimitValue()
    {
        return $this->limit;
    }

    /**
     * Returns the offset value for this query.
     *
     * @return int|string|null
     */
    public function getOffsetValue()
    {
        return $this->offset;
    }

    /**
     * Returns the table alias set via from().
     *
     * @return string|null
     */
    public function getFromAlias()
    {
        return $this->_from;
    }

    /**
     * Get all rows
     *
     * @param array $columns
     *
     * @return Model|array<int, Model>|false
     */
    public function all($columns = ['*'])
    {
        return $this->get($columns);
    }

    /**
     * Renders a select/aggregate column in this query's table context.
     *
     * @return string
     */
    public function prepareColumnName(string $column)
    {
        return $this->renderIdentifier($column, true, true);
    }

    /**
     * Selects column for query
     *
     * @param array $columns
     *
     * @return $this
     */
    public function select($columns = ['*'])
    {
        $select = \is_array($columns) ? $columns : \func_get_args();

        $this->select = [];
        foreach ($select as $column) {
            $this->select[] = $column;
        }

        return $this;
    }

    /**
     * Compiles this query as a single-column key subquery for a relation's
     * IN (...) constraint: only $keyColumn is projected. Strips selectRaw (extra
     * columns would break the operand count) and, unless a LIMIT pins the set,
     * ORDER BY (meaningless for set membership and may reference a stripped raw
     * select alias).
     *
     * @param string $keyColumn
     *
     * @return string
     */
    public function prepareKeySubquery($keyColumn): string
    {
        $clone                    = clone $this;
        $clone->selectRaw         = ['columns' => [], 'bindings' => []];
        $clone->selectExpressions = [];
        $clone->groupBy           = [];
        $clone->having            = [];
        if (!isset($clone->limit)) {
            $clone->orderBy = [];
        }

        return $clone->select($keyColumn)->prepare();
    }

    /**
     * Adds column to select list
     *
     * @param array|string $columns
     *
     * @return $this
     */
    public function addSelect($columns)
    {
        $select = !\is_array($columns) ? \func_get_args() : $columns;

        foreach ($select as $column) {
            if (\in_array($column, $this->select, true)) {
                continue;
            }
            $this->select[] = $column;
        }

        return $this;
    }

    /**
     * Returns framework-generated structured SELECT expressions.
     *
     * @return array<int, array>
     */
    public function getSelectExpressions()
    {
        return $this->selectExpressions;
    }

    /**
     * Selects raw query as column for query
     *
     * @param string $column
     * @param array  $bindings
     *
     * @return $this
     */
    public function selectRaw($column, array $bindings = [])
    {
        $this->selectRaw['columns'][] = $column;
        if (!empty($bindings)) {
            $this->selectRaw['bindings'] = array_merge($this->selectRaw['bindings'], $bindings);
        }

        return $this;
    }

    /**
     * Adds DISTINCT to the SELECT. Note: this does not emit COUNT(DISTINCT ...)
     * and is not pagination-aware (count()/paginate() ignore it).
     *
     * @return $this
     */
    public function distinct()
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * Whether DISTINCT was requested for this SELECT.
     *
     * @return bool
     */
    public function isDistinct()
    {
        return $this->distinct;
    }

    /**
     * Prepare the query and execute.
     *
     * @param array $columns
     *
     * @return Model|array<int, Model>| false
     */
    public function get($columns = ['*'])
    {
        $columns = \is_array($columns) ? $columns : \func_get_args();
        if (empty($this->select) || $columns !== ['*']) {
            $this->select($columns);
        }

        $this->_method = self::SELECT;

        return $this->_model->getInstanceFromBuilder(
            $this->exec(),
            $this->limit == 1 ? true : false
        );
    }

    /**
     * Returns only first row from query result.
     *
     * @return Model
     */
    public function first()
    {
        return $this->take(1)->get();
    }

    /**
     * Prepare the query and execute.
     *
     * @param array $attributes
     *
     * @return Model|array<int, Model>| false
     */
    public function find($attributes)
    {
        if (!\is_array($attributes)) {
            $attributes = [$this->_model->getPrimaryKey() => $attributes];
        }

        foreach ($attributes as $key => $value) {
            $this->where($key, $value);
        }

        return $this->get();
    }

    /**
     * Returns first row
     *
     * @param array $attributes
     *
     * @return Model | false
     */
    public function findOne($attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->where($key, $value);
        }

        return $this->first();
    }

    /**
     * Set where clause
     *
     * @param array<column, ?operator, value> ...$params
     *
     * @return $this
     */
    public function where(...$params)
    {
        $this->prepareWhere($params);

        return $this;
    }

    /**
     * Set where clause with OR pipe
     *
     * @param array<column, ?operator, value> ...$params
     *
     * @return $this
     */
    public function orWhere(...$params)
    {
        $this->prepareWhere($params, 'OR');

        return $this;
    }

    /**
     * Set where clause
     *
     * @param string $sql
     * @param array  $bindings
     *
     * @return $this
     */
    public function whereRaw($sql, $bindings = [])
    {
        $this->where[] = [
            'raw'      => $sql,
            'bindings' => $bindings,
        ];

        return $this;
    }

    /**
     * Set where clause and or pipe
     *
     * @param string $sql
     * @param array  $bindings
     *
     * @return $this
     */
    public function orWhereRaw($sql, $bindings = [])
    {
        $this->where[] = [
            'raw'      => $sql,
            'bindings' => $bindings,
            'bool'     => 'OR',
        ];

        return $this;
    }

    /**
     * Set where clause with IN operator
     *
     * @param string $column
     * @param array  $value
     *
     * @return $this
     */
    public function whereIn($column, $value)
    {
        $this->assertSafeIdentifier($column);

        if (\is_array($value)) {
            if ($value === []) {
                return $this->whereRaw('0 = 1');
            }

            $value = $this->sanitizeInValues($value);
        }

        $this->where[] = [
            'column'   => $column,
            'value'    => $value,
            'operator' => 'IN',
        ];

        return $this;
    }

    /**
     * Set where clause with null condition
     *
     * @param string $column
     *
     * @return $this
     */
    public function whereNull($column)
    {
        $this->assertSafeIdentifier($column);

        $this->where[] = [
            'column'   => $column,
            'operator' => 'IS NULL',
        ];

        return $this;
    }

    /**
     * Set where clause with not null condition
     *
     * @param string $column
     *
     * @return $this
     */
    public function whereNotNull($column)
    {
        $this->assertSafeIdentifier($column);

        $this->where[] = [
            'column'   => $column,
            'operator' => 'IS NOT NULL',
        ];

        return $this;
    }

    /**
     * Include soft-deleted rows in the result set.
     *
     * @return $this
     */
    public function withTrashed()
    {
        $this->_withTrashed = true;

        return $this;
    }

    /**
     * Restrict results to only soft-deleted rows.
     *
     * @return $this
     */
    public function onlyTrashed()
    {
        $this->_onlyTrashed = true;

        return $this;
    }

    /**
     * Set where clause with between condition
     *
     * @param string $column
     * @param mixed  $start
     * @param mixed  $end
     *
     * @return $this
     */
    public function whereBetween($column, $start, $end)
    {
        $this->assertSafeIdentifier($column);

        $this->where[] = [
            'column'   => $column,
            'operator' => SqlOperator::normalizeRange('BETWEEN'),
            'between'  => [$start, $end],
        ];

        return $this;
    }

    /**
     * Set where clause with between condition and or pipe
     *
     * @param string $column
     * @param mixed  $start
     * @param mixed  $end
     *
     * @return $this
     */
    public function orWhereBetween($column, $start, $end)
    {
        $this->assertSafeIdentifier($column);

        $this->where[] = [
            'column'   => $column,
            'operator' => SqlOperator::normalizeRange('BETWEEN'),
            'between'  => [$start, $end],
            'bool'     => 'OR',
        ];

        return $this;
    }

    /**
     * Returns pagination data
     *
     * @param int $pageNo
     * @param int $perPage
     *
     * @return array
     */
    public function paginate($pageNo = 0, $perPage = 10)
    {
        if (empty($this->select)) {
            $this->select = ['*'];
        }

        $totalItems = (int) $this->count();

        $offset = ($pageNo > 1) ? ($pageNo * $perPage) - $perPage : 0;

        $data = $this->take($perPage)->skip($offset)->get();

        $pages = ceil($totalItems / $perPage);

        return [
            'data'          => $data,
            'pages'         => $pages,
            'total'         => $totalItems,
            'current_total' => is_countable($data) ? \count($data) : 0,
            'current_page'  => $pageNo,
            'last_page'     => $pages,
            'per_page'      => $perPage,
        ];
    }

    /**
     * Sets group by clause
     *
     * @param array|string[] $columns
     *
     * @return $this
     */
    public function groupBy($columns)
    {
        $columns = \is_array($columns) ? $columns : \func_get_args();
        foreach ($columns as $column) {
            $this->assertSafeIdentifier($column);
        }

        $this->groupBy = array_merge($this->groupBy, $columns);

        return $this;
    }

    /**
     * Set having clause
     *
     * @param array<column, ?operator, value> ...$params
     *
     * @return $this
     */
    public function having(...$params)
    {
        return $this->prepareHaving($params);
    }

    /**
     * Set having clause with or
     *
     * @param array<column, ?operator, value> ...$params
     *
     * @return $this
     */
    public function orHaving(...$params)
    {
        return $this->prepareHaving($params, 'OR');
    }

    /**
     * Sets join
     *
     * @param string $table
     * @param string $firstColumn
     * @param string $operator
     * @param string $secondColumn
     * @param string $type
     *
     * @return $this
     */
    public function join($table, $firstColumn, $operator = null, $secondColumn = null, $type = 'INNER')
    {
        [$rawTable, $alias, $prefixedTable, $reference] = $this->parseJoinTable($table);

        return $this->storeJoin(
            $rawTable,
            $alias,
            $prefixedTable,
            $reference,
            $this->prepareOnColumn($reference, $firstColumn, $operator, $secondColumn),
            $type
        );
    }

    /**
     * Joins a table using a bound scalar value as the right-hand operand.
     *
     * @param mixed $table
     * @param mixed $firstColumn
     * @param mixed $operator
     * @param mixed $value
     * @param mixed $type
     *
     * @return $this
     */
    public function joinWhere($table, $firstColumn, $operator, $value, $type = 'INNER')
    {
        [$rawTable, $alias, $prefixedTable, $reference] = $this->parseJoinTable($table);

        return $this->storeJoin(
            $rawTable,
            $alias,
            $prefixedTable,
            $reference,
            $this->prepareOnValue($firstColumn, $operator, $value),
            $type
        );
    }

    /**
     * Maps each unprefixed table name this query knows about (the model's own
     * table plus every non-aliased join) to its physical, prefixed name.
     * Aliased joins are excluded: they are referenced by their alias, not the
     * physical table, so their columns must not be rewritten.
     *
     * @return array<string, string>
     */
    public function getTableMap()
    {
        $map = [$this->_model->getTableWithoutPrefix() => $this->table];
        foreach ($this->joins as $join) {
            if ($join['userAlias'] === null) {
                $map[$join['raw']] = $join['prefixed'];
            }
        }

        return $map;
    }

    /**
     * Table aliases in scope (from() alias + every join alias). A qualifier that
     * matches an alias is never rewritten to a physical table name.
     *
     * @return array<int, string>
     */
    public function getTableAliases()
    {
        $aliases = [];
        if (!\is_null($this->_from)) {
            $aliases[] = $this->_from;
        }
        foreach ($this->joins as $join) {
            if ($join['userAlias'] !== null) {
                $aliases[] = $join['userAlias'];
            }
        }

        return $aliases;
    }

    /**
     * Renders a structured column identifier in this query's table context.
     *
     * Bare columns are qualified with the model's physical table. A qualified
     * column must name a registered logical table, its mapped physical table,
     * or an alias in scope. Unknown and schema-qualified names intentionally
     * fail closed; callers needing those rare forms must register the table or
     * alias, or use a reviewed raw-expression API.
     *
     * @param mixed $column
     *
     * @return mixed
     */
    public function resolveQualifier($column)
    {
        if (!\is_string($column)) {
            return $column;
        }

        return $this->renderIdentifier($column, true, true);
    }

    /**
     * Validates, resolves, and quotes a column at the SQL rendering boundary.
     *
     * @param string $column
     * @param bool   $allowWildcard
     * @param bool   $allowAlias
     *
     * @return string
     */
    public function renderIdentifier(string $column, bool $allowWildcard = false, bool $allowAlias = false): string
    {
        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', $column, $matches)) {
            if (!$allowAlias) {
                throw new RuntimeException('Aliases are not allowed in this SQL identifier context.');
            }

            return $this->renderIdentifier($matches[1], $allowWildcard)
                . ' AS ' . Identifier::quoteAlias($matches[2]);
        }

        $segments = explode('.', $column);
        if (\count($segments) > 2) {
            throw new RuntimeException('Unknown or schema-qualified SQL identifier.');
        }

        Identifier::quoteQualified($column, $allowWildcard);

        if (\count($segments) === 1) {
            $baseReference = $this->_from ?? $this->table;

            return Identifier::quoteQualified($baseReference . '.' . $column, $allowWildcard);
        }

        [$qualifier, $name] = $segments;
        $aliases            = $this->getTableAliases();
        $map                = $this->getTableMap();

        if (\in_array($qualifier, $aliases, true)) {
            $resolved = $qualifier;
        } elseif ($this->_from !== null
            && \in_array($qualifier, [$this->_model->getTableWithoutPrefix(), $this->table], true)
        ) {
            $resolved = $this->_from;
        } elseif (isset($map[$qualifier])) {
            $resolved = $map[$qualifier];
        } elseif (\in_array($qualifier, array_values($map), true)) {
            $resolved = $qualifier;
        } else {
            throw new RuntimeException('Unknown SQL identifier qualifier.');
        }

        return Identifier::quoteQualified($resolved . '.' . $name, $allowWildcard);
    }

    /**
     * Sets left join
     *
     * @param string $table
     * @param string $firstColumn
     * @param string $operator
     * @param string $secondColumn
     *
     * @return $this
     */
    public function leftJoin($table, $firstColumn, $operator = null, $secondColumn = null)
    {
        return $this->join($table, $firstColumn, $operator, $secondColumn, 'LEFT');
    }

    /**
     * Sets right join
     *
     * @param string $table
     * @param string $firstColumn
     * @param string $operator
     * @param string $secondColumn
     *
     * @return $this
     */
    public function rightJoin($table, $firstColumn, $operator = null, $secondColumn = null)
    {
        return $this->join($table, $firstColumn, $operator, $secondColumn, 'RIGHT');
    }

    /**
     * Sets right join
     *
     * @param string $table
     * @param string $firstColumn
     * @param string $operator
     * @param string $secondColumn
     *
     * @return $this
     */
    public function fullJoin($table, $firstColumn, $operator = null, $secondColumn = null)
    {
        return $this->join($table, $firstColumn, $operator, $secondColumn, 'FULL');
    }

    /**
     * Sets cross join
     *
     * @param string $table
     * @param string $firstColumn
     * @param string $operator
     * @param string $secondColumn
     *
     * @return $this
     */
    public function crossJoin($table, $firstColumn, $operator = null, $secondColumn = null)
    {
        return $this->join($table, $firstColumn, $operator, $secondColumn, 'CROSS');
    }

    /**
     * Sets on clause for join
     *
     * @param string $firstColumn
     * @param string $operator
     * @param string $secondColumn
     * @param string $bool
     *
     * @return $this
     */
    public function on($firstColumn, $operator = null, $secondColumn = null, $bool = 'AND')
    {
        $joinIndex = (\count($this->joins) - 1);
        if ($joinIndex < 0) {
            throw new RuntimeException('Cannot add an ON clause before a join.');
        }

        $table                           = $this->joins[$joinIndex]['alias'];
        $this->joins[$joinIndex]['on'][] = $this->prepareOnColumn($table, $firstColumn, $operator, $secondColumn, $bool);

        return $this;
    }

    /**
     * Sets or on clause for join
     *
     * @param string $firstColumn
     * @param string $operator
     * @param string $secondColumn
     *
     * @return $this
     */
    public function orOn($firstColumn, $operator = null, $secondColumn = null)
    {
        return $this->on($firstColumn, $operator, $secondColumn, 'OR');
    }

    /**
     * Adds an ON condition whose right-hand operand is a bound scalar value.
     *
     * @param mixed $firstColumn
     * @param mixed $operator
     * @param mixed $value
     * @param mixed $bool
     *
     * @return $this
     */
    public function onValue($firstColumn, $operator, $value, $bool = 'AND')
    {
        $joinIndex = (\count($this->joins) - 1);
        if ($joinIndex < 0) {
            throw new RuntimeException('Cannot add an ON clause before a join.');
        }

        $this->joins[$joinIndex]['on'][] = $this->prepareOnValue($firstColumn, $operator, $value, $bool);

        return $this;
    }

    /**
     * Adds an OR ON condition whose right-hand operand is a bound scalar value.
     *
     * @param mixed $firstColumn
     * @param mixed $operator
     * @param mixed $value
     *
     * @return $this
     */
    public function orOnValue($firstColumn, $operator, $value)
    {
        return $this->onValue($firstColumn, $operator, $value, 'OR');
    }

    /**
     * Adds an explicitly raw ON condition. The SQL must be developer-authored;
     * dynamic values belong in placeholders and $bindings.
     *
     * @param mixed $sql
     * @param mixed $bool
     *
     * @return $this
     */
    public function onRaw($sql, array $bindings = [], $bool = 'AND')
    {
        $joinIndex = (\count($this->joins) - 1);
        if ($joinIndex < 0) {
            throw new RuntimeException('Cannot add an ON clause before a join.');
        }

        if (!\is_string($sql) || $sql === '') {
            throw new RuntimeException('Invalid raw ON expression.');
        }

        $this->joins[$joinIndex]['on'][] = [
            'raw'      => $sql,
            'bindings' => $bindings,
            'bool'     => $this->normalizeBoolean($bool),
        ];

        return $this;
    }

    /**
     * Adds an explicitly raw OR ON condition.
     *
     * @param mixed $sql
     *
     * @return $this
     */
    public function orOnRaw($sql, array $bindings = [])
    {
        return $this->onRaw($sql, $bindings, 'OR');
    }

    /**
     * Sets order by
     *
     * @param string $column
     *
     * @return $this
     */
    public function orderBy($column)
    {
        $this->assertSafeIdentifier($column);

        $this->orderBy[] = [
            'column'    => $column,
            'direction' => 'ASC',
        ];

        return $this;
    }

    /**
     * Sets order by raw query
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return $this
     */
    public function orderByRaw($query, $bindings = [])
    {
        $this->orderBy[] = [
            'raw'      => $query,
            'bindings' => $bindings,
        ];

        return $this;
    }

    /**
     * Sets ascending order
     *
     * @return $this
     */
    public function asc()
    {
        $orderId = \count($this->orderBy);
        if ($orderId > 0) {
            $orderId = $orderId - 1;
        }

        $this->orderBy[$orderId]['direction'] = 'ASC';
        if (!isset($this->orderBy[$orderId]['column'])) {
            $this->orderBy[$orderId]['column'] = $this->_model->getPrimaryKey();
        }

        return $this;
    }

    /**
     * Sets descending order
     *
     * @return $this
     */
    public function desc()
    {
        $orderId = \count($this->orderBy);
        if ($orderId > 0) {
            $orderId = $orderId - 1;
        }

        $this->orderBy[$orderId]['direction'] = 'DESC';
        if (!isset($this->orderBy[$orderId]['column'])) {
            $this->orderBy[$orderId]['column'] = $this->_model->getPrimaryKey();
        }

        return $this;
    }

    /**
     * Runs a constrained, developer-authored SQL template.
     *
     * Dynamic values must use wpdb placeholders. Dynamic identifiers and sort
     * directions must use typed markers and exactly matching maps. Request data
     * must never supply the template itself.
     *
     * @return mixed
     */
    public function rawPrepared(
        string $template,
        array $bindings = [],
        array $identifiers = [],
        array $directions = []
    ) {
        $sql = RawTemplate::compile($template, $bindings, $identifiers, $directions);

        if ($bindings === []) {
            $sql = str_replace('%%', '%', $sql);
        } else {
            $prepared = Connection::prepare($sql, $bindings);
            if (!\is_string($prepared) || $prepared === '') {
                throw new RuntimeException('SQL query preparation failed.');
            }
            $sql = $prepared;
        }

        return $this->unsafeRaw($sql);
    }

    /**
     * Runs reviewed, fully developer-controlled SQL through the legacy path.
     *
     * Bind every dynamic value. Request data must never be interpolated into
     * the SQL string.
     *
     * @param string $sql
     * @param array  $bindings
     *
     * @return mixed
     */
    public function unsafeRaw(string $sql, array $bindings = [])
    {
        $this->_method  = self::RAW;
        $this->raw      = $sql;
        $this->bindings = $bindings;

        $result = $this->exec();

        if (preg_match('/^SELECT/i', trim($sql))) {
            $result = Connection::prop('last_result');
        }

        $this->raw = '';
        unset($this->_method);

        return $result;
    }

    /**
     * Runs raw SQL through the legacy unsafe implementation.
     *
     * @deprecated Use rawPrepared() for typed templates or unsafeRaw() for
     *             reviewed, fully developer-controlled legacy SQL.
     *
     * @param string $sql
     * @param array  $bindings
     *
     * @return mixed
     */
    public function raw($sql, $bindings = [])
    {
        return $this->unsafeRaw($sql, $bindings);
    }

    public function prepareRaw()
    {
        return $this->raw;
    }

    /**
     * Sets limit
     *
     * @param string $count
     *
     * @return $this
     */
    public function take($count)
    {
        $this->limit = (int) $count;

        return $this;
    }

    /**
     * Sets offset
     *
     * @param string $count
     *
     * @return $this
     */
    public function skip($count)
    {
        $this->offset = (int) $count;

        return $this;
    }

    /**
     * Run insert query for model
     *
     * @param array $attributes
     *
     * @return Model|array<int, Model>|false
     */
    public function insert($attributes = [])
    {
        if (empty($attributes)) {
            return false;
        }

        if ($this->isListOfRows($attributes)) {
            return $this->bulkInsert($attributes);
        }

        $this->normalizeWriteColumns(array_keys($attributes));
        $this->_model->fill($attributes);
        if ($this->save()) {
            return $this->_model;
        }

        return false;
    }

    /**
     * Runs update query for model
     *
     * @param array $attributes
     *
     * @return $this
     */
    public function update($attributes = [])
    {
        if (empty($attributes)) {
            return false;
        }

        $this->normalizeWriteColumns(array_keys($attributes));
        $this->_model->fill($attributes);
        if ($this->_model->exists()) {
            return $this->save();
        }

        $this->update = $this->prepareAttributeForSaveOrUpdate(true);
        if (empty($this->update)) {
            return false;
        }

        return $this->exec();
    }

    /**
     * Runs delete query for multiple model
     *
     * @param array $ids
     *
     * @return string|bool
     */
    public function destroy($ids = [])
    {
        $this->_method = self::DELETE;
        $this->where($this->_model->getPrimaryKey(), $ids);

        return $this->exec();
    }

    /**
     * Saves the current model
     *
     * @return string|bool
     */
    public function save()
    {
        $this->normalizeWriteColumns(
            $this->withoutRelationColumns(array_keys($this->_model->getAttributes()))
        );

        if ($this->_model->fireEvent('saving') === false) {
            return false;
        }

        $columns = $this->prepareAttributeForSaveOrUpdate($this->_model->exists());
        $pk      = $this->_model->getPrimaryKey();
        if ($this->_model->exists()) {
            if (empty($columns)) {
                $this->_model->fireEvent('saved');

                return $this->_model;
            }

            $isPkExistsInWhere = false;

            $pkValue = $this->_model->getAttribute($pk);
            foreach ($this->where as $value) {
                if ($value['column'] == $pk && $value['value'] == $pkValue && !isset($value['operator'])) {
                    $isPkExistsInWhere = true;
                }
            }

            if (!$isPkExistsInWhere) {
                $this->where($pk, $pkValue);
            }

            $this->update = $columns;

            // 0-row (no-op) UPDATE is success; exec() is false only on error/cancel.
            if ($this->exec() !== false) {
                $this->_model->fireEvent('saved');

                return $this->_model;
            }

            return false;
        }

        if (empty($columns)) {
            return false;
        }

        $this->insert = $columns;
        if ($this->exec() === false) {
            return false;
        }

        $this->_model->fireEvent('saved');

        return $this->_model;
    }

    /**
     * Get counts for current model
     *
     * @return int
     */
    public function count()
    {
        return (int) $this->aggregate('COUNT', $this->_model->getPrimaryKey());
    }

    public function max($column)
    {
        return $this->aggregate('MAX', $column);
    }

    public function min($column)
    {
        return $this->aggregate('MIN', $column);
    }

    public function avg($column)
    {
        return $this->aggregate('AVG', $column);
    }

    public function sum($column)
    {
        return $this->aggregate('SUM', $column);
    }

    public function aggregate($function, $column)
    {
        $this->assertSafeAggregateFunction($function);

        $query                    = $this->clone();
        $query->select            = [];
        $query->selectRaw         = ['columns' => [], 'bindings' => []];
        $query->selectExpressions = [];
        $query->distinct          = false;
        $preparedColumn           = $column === '*' ? '*' : $query->prepareColumnName($column);
        $result                   = $query->selectRaw($function . '(' . $preparedColumn . ') as ' . $function)->exec();

        return \is_array($result) && isset($result[0]->{$function}) ? $result[0]->{$function} : null;
    }

    public function delete()
    {
        $this->_method = self::DELETE;
        if ($this->_model->exists()) {
            $this->where($this->_model->getPrimaryKey(), $this->_model->getAttribute($this->_model->getPrimaryKey()));
        }

        return $this->exec();
    }

    /**
     * Permanently deletes the targeted rows of a soft-delete model, bypassing
     * the soft-delete rewrite (emits a real DELETE).
     *
     * @return string|bool
     */
    public function forceDelete()
    {
        if (!$this->isSoftDeleteModel()) {
            throw new RuntimeException('forceDelete() is only available on soft-delete models.');
        }

        $this->_forceDelete = true;

        return $this->delete();
    }

    /**
     * Restores soft-deleted rows by nulling deleted_at and persisting.
     *
     * @return string|bool|Model
     */
    public function restore()
    {
        if (!$this->isSoftDeleteModel()) {
            throw new RuntimeException('restore() is only available on soft-delete models.');
        }

        return $this->update(['deleted_at' => null]);
    }

    /**
     * Starts transaction
     *
     * @deprecated Use Connection::startTransaction() instead
     *
     * @return bool
     */
    public function startTransaction()
    {
        return Connection::query('START TRANSACTION');
    }

    /**
     * Commits current transaction
     *
     * @deprecated Use Connection::commit() instead
     *
     * @return bool
     */
    public function commit()
    {
        return Connection::query('COMMIT');
    }

    /**
     * Rollback previously execute query
     *
     * @deprecated Use Connection::rollback() instead
     *
     * @return void
     */
    public function rollback()
    {
        return Connection::query('ROLLBACK');
    }

    /**
     * Sanitize query string
     *
     * @param string|null $sql
     *
     * @return string
     */
    public function prepare($sql = null)
    {
        if (\is_null($sql)) {
            $sql = $this->toSql();
        }

        return empty($this->bindings)
         || strpos($sql, '%') === false
         ? $sql : Connection::prepare($sql, $this->bindings);
    }

    /**
     * Prepares current query string
     *
     * @return string
     */
    public function toSql()
    {
        $sql = '';
        if (isset($this->_method)) {
            switch ($this->_method) {
                case self::SELECT:
                    $sql = $this->grammar()->compileSelect($this);

                    break;
                case self::INSERT:
                    $sql = $this->prepareInsert();

                    break;
                case self::UPDATE:
                    $sql = $this->prepareUpdate();

                    break;
                case self::DELETE:
                    $sql = $this->prepareDelete();

                    break;
                case self::RAW:
                    $sql = $this->prepareRaw();

                    break;
            }
        } elseif (!empty($this->select) || !empty($this->selectExpressions) || !empty($this->selectRaw)) {
            $this->_method = self::SELECT;
            $sql           = $this->grammar()->compileSelect($this);
        }

        return $sql;
    }

    public function when($value = null, ?callable $callback = null, ?callable $default = null)
    {
        $value = $value instanceof Closure ? $value($this) : $value;

        if ($value) {
            return $callback($this, $value) ?? $this;
        } elseif ($default) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    public function upsert(array $values, ?array $update = null)
    {
        if (empty($values)) {
            return false;
        }

        if (!$this->isListOfRows($values)) {
            $values = [$values];
        }

        $manageTimestamps = property_exists($this->_model, 'timestamps') && $this->_model->timestamps;
        [$columns, $rows] = $this->normalizeWriteRows(
            $values,
            $manageTimestamps ? ['created_at', 'updated_at'] : []
        );
        if (empty($columns)) {
            return false;
        }

        if (empty($update)) {
            $update = $columns;
        }
        $update = $this->normalizeWriteColumns($update);

        if ($manageTimestamps) {
            // Never overwrite the original creation time on update; always bump updated_at.
            $update = array_values(array_diff($update, ['created_at']));
            if (!\in_array('updated_at', $update, true)) {
                $update[] = 'updated_at';
            }
        }

        $this->bindings = [];
        $sql            = 'INSERT INTO ' . $this->table;
        $sql .= ' (' . implode(', ', $this->compileWriteColumns($columns)) . ')';

        $sql .= ' VALUES ';
        $insertAbleValues = [];
        foreach ($rows as $row) {
            $insertAbleValues[] = ' ('
                . implode(
                    ', ',
                    array_map(fn ($value) => $this->compileWriteValue($value), $row)
                ) . ')';
        }

        $sql .= empty($insertAbleValues) ? ' default values' : ' ' . implode(',', $insertAbleValues);
        $sql .= ' ON DUPLICATE KEY UPDATE ';
        $sql .= implode(', ', array_map(function ($column) {
            $column = $this->compileWriteColumn($column);

            return $column . ' = VALUES(' . $column . ')';
        }, $update));
        $sql .= ';';

        return $this->raw($sql, $this->bindings);
    }

    /**
     * Returns types
     *
     * @param mixed $value
     *
     * @return string
     */
    public function getValueType($value)
    {
        $placeHolder = '%s';

        if (\gettype($value) == 'integer') {
            $placeHolder = '%d';
        } elseif (\gettype($value) == 'double') {
            $placeHolder = '%f';
        }

        return $placeHolder;
    }

    /**
     * Prepares conditional
     *
     * @param array  $params
     * @param string $bool
     * @param string $type
     *
     * @return array|void
     */
    protected function prepareConditional($params, $bool = 'AND', $type = 'where')
    {
        $noOfParams = \count($params);
        $conditions = [];
        if ($noOfParams === 0) {
            return $conditions;
        }

        $bool = $this->normalizeBoolean($bool);

        if (\func_num_args() == 1 && \is_array($params[0])) {
            foreach ($params[0] as $clause) {
                if ($type === 'where') {
                    $this->prepareWhere($clause, $bool);
                }
            }

            return;
        }

        $conditions['bool'] = $bool;
        if ($params[0] instanceof Closure) {
            $nestedQuery = $this->newNestedQuery()->queryFor($type);
            \call_user_func($params[0], $nestedQuery);
            $conditions['query'] = $nestedQuery;
            if (isset($params[1])) {
                $conditions['bool'] = $this->normalizeBoolean($params[1]);
            }
        } elseif ($noOfParams == 2) {
            $conditions['column'] = $params[0];
            $conditions['value']  = $params[1];
        } elseif ($noOfParams == 3) {
            $conditions['column']   = $params[0];
            $conditions['operator'] = $params[1];
            $conditions['value']    = $params[2];
        } elseif ($noOfParams == 4) {
            $conditions['column']                                     = $params[0];
            $conditions['operator']                                   = $params[1];
            $conditions[$type === 'where' ? 'value' : 'secondColumn'] = $params[2];
            $conditions['bool']                                       = $this->normalizeBoolean($params[3]);
        }

        return $this->normalizeConditions($conditions, $type);
    }

    /**
     * Prepares where conditions
     *
     * @param array  $params
     * @param string $bool
     *
     * @return void
     */
    protected function prepareWhere($params, $bool = 'AND')
    {
        $conditions = $this->prepareConditional($params, $bool);
        if (!empty($conditions)) {
            $this->where[] = $conditions;
        }
    }

    /**
     * Prepare having
     *
     * @param array  $params
     * @param string $bool
     *
     * @return $this
     */
    protected function prepareHaving($params, $bool = 'AND')
    {
        $conditions = $this->prepareConditional($params, $bool, 'having');
        if (!empty($conditions)) {
            $this->having[] = $conditions;
        }

        return $this;
    }

    /**
     * Prepares on
     *
     * @param string $table
     * @param string $firstColumn
     * @param string $operator
     * @param string $secondColumn
     * @param string $bool
     * @param mixed  $column
     *
     * @return $this
     */
    protected function prepareOnColumn($table, $column, $operator, $secondColumn, $bool = 'AND')
    {
        if (\is_null($operator) && \is_null($secondColumn)) {
            $secondColumn = $column;
            $operator     = '=';
        }

        $this->assertSafeIdentifier($column);
        $this->assertSafeIdentifier($secondColumn);
        $operator = SqlOperator::normalizeBinary($operator);
        $bool     = $this->normalizeBoolean($bool);

        if (strpos($secondColumn, '.') === false) {
            $secondColumn = $table . '.' . $secondColumn;
        }

        return compact('column', 'operator', 'secondColumn', 'bool');
    }

    /**
     * Prepares a join condition with a bound right-hand value.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @param mixed $bool
     *
     * @return array
     */
    protected function prepareOnValue($column, $operator, $value, $bool = 'AND')
    {
        $this->assertSafeIdentifier($column);
        if (\is_array($value) || \is_object($value) || \is_resource($value)) {
            throw new RuntimeException('Join values must be scalar or null.');
        }

        $operator = SqlOperator::normalizeBinary($operator);
        $bool     = $this->normalizeBoolean($bool);
        if (\is_null($value)) {
            $operator = $this->nullOperator($operator);

            return compact('column', 'operator', 'bool');
        }

        return compact('column', 'operator', 'value', 'bool');
    }

    /**
     * Helper function, to get current timestamp
     *
     * @return string
     */
    protected function currentTimestamp()
    {
        $timezoneString = $this->getTimeZone();

        $dateTime = new DateTime('now', new DateTimeZone($timezoneString));

        return $dateTime->format(self::TIME_FORMAT);
    }

    protected function getTimeZone()
    {
        if (isset(static::$TIME_ZONE)) {
            $timezoneString = static::$TIME_ZONE;
        } elseif (\function_exists('wp_timezone_string')) {
            $timezoneString = wp_timezone_string();
        } elseif (!($timezoneString = get_option('timezone_string'))) {
            $offset  = (float) get_option('gmt_offset');
            $hours   = (int) $offset;
            $minutes = ($offset - $hours);

            $sign    = ($offset < 0) ? '-' : '+';
            $absHour = abs($hours);
            $absMins = abs($minutes * 60);

            $timezoneString = \sprintf('%s%02d:%02d', $sign, $absHour, $absMins);
        }

        return $timezoneString;
    }

    /**
     * Adds an aggregate SELECT expression without routing its identifier
     * through the raw SQL channel.
     *
     * @param mixed       $function
     * @param mixed       $column
     * @param null|string $alias
     *
     * @return $this
     */
    private function addAggregateSelect($function, $column, $alias = null)
    {
        $this->assertSafeAggregateFunction($function);
        if ($column !== '*') {
            $this->assertSafeIdentifier($column);
        }
        if ($alias !== null) {
            Identifier::assertSimple($alias);
        }

        $this->selectExpressions[] = [
            'type'     => 'aggregate',
            'function' => $function,
            'column'   => $column,
            'alias'    => $alias,
        ];

        return $this;
    }

    /**
     * Adds a relation subquery SELECT expression with a validated output alias.
     * The nested builder remains structured until Grammar compilation.
     *
     * @param mixed $exists
     *
     * @return $this
     */
    private function addSubquerySelect(QueryBuilder $query, string $alias, $exists = false)
    {
        Identifier::assertSimple($alias);

        $this->selectExpressions[] = [
            'type'   => 'subquery',
            'query'  => $query,
            'alias'  => $alias,
            'exists' => (bool) $exists,
        ];

        return $this;
    }

    /**
     * Parses a structured join table declaration with an optional explicit
     * `AS` alias. Both identifiers remain logical state until compilation.
     *
     * @param mixed $table
     *
     * @return array{string, null|string, string, string}
     */
    private function parseJoinTable($table)
    {
        if (!\is_string($table)
            || !preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+AS\s+([A-Za-z_][A-Za-z0-9_]*))?$/i', $table, $matches)
        ) {
            throw new RuntimeException('Invalid SQL join table declaration.');
        }

        $rawTable      = $matches[1];
        $alias         = isset($matches[2]) ? $matches[2] : null;
        $prefixedTable = $this->_model->getTablePrefix() . $rawTable;
        $reference     = $alias !== null ? $alias : $prefixedTable;

        Identifier::assertSimple($rawTable);
        Identifier::assertSimple($prefixedTable);
        if ($alias !== null) {
            Identifier::assertSimple($alias);
        }

        return [$rawTable, $alias, $prefixedTable, $reference];
    }

    /**
     * Stores one validated join and its first ON condition.
     *
     * @param mixed $rawTable
     * @param mixed $alias
     * @param mixed $prefixedTable
     * @param mixed $reference
     * @param mixed $type
     *
     * @return $this
     */
    private function storeJoin($rawTable, $alias, $prefixedTable, $reference, array $on, $type)
    {
        $this->joins[] = [
            'table'     => $prefixedTable,
            'alias'     => $reference,
            'on'        => [$on],
            'type'      => JoinType::normalize($type),
            'raw'       => $rawTable,
            'prefixed'  => $prefixedTable,
            'userAlias' => $alias,
        ];

        return $this;
    }

    /**
     * Creates a nested builder that compiles its conditions inside this query's
     * table context: it shares the model (via newQuery()) plus this query's
     * joins and from() alias, so resolveQualifier() inside a nested where group
     * resolves joined-table qualifiers exactly as at top level. The joins stay
     * inert — a nested builder compiles only its conditions, never JOIN SQL.
     *
     * @return QueryBuilder
     */
    private function newNestedQuery()
    {
        $query        = $this->newQuery();
        $query->joins = $this->joins;
        $query->_from = $this->_from;

        return $query;
    }

    /**
     * Guards an aggregate function name that is interpolated straight into SQL:
     * only a bare identifier is allowed, so parens/spaces/semicolons cannot
     * smuggle in a payload. Case is preserved (SQL function names are
     * case-insensitive).
     *
     * @param mixed $function
     *
     * @return void
     */
    private function assertSafeAggregateFunction($function)
    {
        if (!\is_string($function) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $function)) {
            throw new RuntimeException('Invalid aggregate function name.');
        }
    }

    /**
     * Coerces each IN-list element to a scalar so it maps to exactly one
     * placeholder and one binding (a nested array/object is JSON-encoded, never
     * flattened into multiple bindings).
     *
     * @param array $values
     *
     * @return array
     */
    private function sanitizeInValues(array $values)
    {
        return array_map(
            function ($value) {
                if (\is_array($value) || \is_object($value)) {
                    return wp_json_encode($value);
                }

                return $value;
            },
            $values
        );
    }

    /**
     * True only when $attributes is a non-empty positional list whose every
     * element is itself an array (a list of rows for bulk insert). An assoc row
     * whose first value happens to be an array must take the single-row path.
     *
     * @param mixed $attributes
     *
     * @return bool
     */
    private function isListOfRows($attributes)
    {
        if (!\is_array($attributes) || $attributes === []) {
            return false;
        }

        if (array_keys($attributes) !== range(0, \count($attributes) - 1)) {
            return false;
        }

        foreach ($attributes as $row) {
            if (!\is_array($row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Repairs where-clause values that would otherwise emit invalid SQL or fatal
     * on prepare, independent of arity: an empty array becomes the false
     * constant `0 = 1`; a null value with an explicit operator becomes
     * IS [NOT] NULL; a non-empty array has its elements coerced to scalars; an
     * object value is JSON-encoded. Explicit list operators are normalized by
     * value shape for both where and having clauses.
     *
     * @param array  $conditions
     * @param string $type
     *
     * @return array
     */
    private function normalizeConditions(array $conditions, $type)
    {
        if (isset($conditions['bool'])) {
            $conditions['bool'] = $this->normalizeBoolean($conditions['bool']);
        }

        if (isset($conditions['column'])) {
            $this->assertSafeIdentifier($conditions['column']);
        }

        if (!\array_key_exists('value', $conditions)) {
            return $conditions;
        }

        $value = $conditions['value'];
        $bool  = isset($conditions['bool']) ? $conditions['bool'] : 'AND';

        if (\is_array($value)) {
            $operator = 'IN';
            if (isset($conditions['operator'])) {
                $operator               = SqlOperator::normalizeList($conditions['operator']);
                $conditions['operator'] = $operator;
            }

            if ($value === []) {
                return [
                    'bool'     => $bool,
                    'raw'      => $operator === 'NOT IN' ? '1 = 1' : '0 = 1',
                    'bindings' => [],
                ];
            }

            $conditions['value'] = $this->sanitizeInValues($value);

            return $conditions;
        }

        if (isset($conditions['operator'])) {
            $conditions['operator'] = SqlOperator::normalizeBinary($conditions['operator']);
        }

        if ($type !== 'where') {
            return $conditions;
        }

        if (\is_null($value)) {
            if (isset($conditions['operator'])) {
                $conditions['operator'] = $this->nullOperator($conditions['operator']);
            } else {
                $conditions['operator'] = 'IS NULL';
            }

            unset($conditions['value']);

            return $conditions;
        }

        if (\is_object($value)) {
            $conditions['value'] = wp_json_encode($value);
        }

        return $conditions;
    }

    /**
     * Maps a comparison operator to its null-safe form for a null value.
     *
     * @param string $operator
     *
     * @return string
     */
    private function nullOperator($operator)
    {
        $negations = ['!=', '<>', 'NOT LIKE', 'NOT IN', 'IS NOT NULL'];

        return \in_array($operator, $negations, true) ? 'IS NOT NULL' : 'IS NULL';
    }

    /**
     * Normalizes a structured boolean connector without accepting whitespace
     * or comments around it.
     *
     * @param mixed $bool
     *
     * @return string
     */
    private function normalizeBoolean($bool)
    {
        if (!\is_string($bool)) {
            throw new RuntimeException('Invalid SQL boolean connector.');
        }

        $normalized = strtoupper($bool);
        if (!\in_array($normalized, ['AND', 'OR'], true)) {
            throw new RuntimeException('Invalid SQL boolean connector.');
        }

        return $normalized;
    }

    /**
     * Guards a structured column boundary. Only plain or qualified logical
     * identifiers are accepted; raw expressions belong in an explicitly raw
     * API and contextual qualification remains deferred until compilation.
     *
     * @param mixed $column
     *
     * @return void
     */
    private function assertSafeIdentifier($column)
    {
        if (!\is_string($column)) {
            throw new RuntimeException('Invalid structured SQL identifier.');
        }

        Identifier::quoteQualified($column);
    }

    /**
     * Returns true when the model declares soft-delete support.
     *
     * @return bool
     */
    private function isSoftDeleteModel()
    {
        return property_exists($this->_model, 'soft_deletes') && $this->_model->soft_deletes;
    }

    /**
     * Returns true when soft-delete read scope is active for the model.
     *
     * Soft-delete models exclude trashed rows by default; a model opts out by
     * declaring public $soft_delete_scope = false.
     *
     * @return bool
     */
    private function autoScopeEnabled()
    {
        if (property_exists($this->_model, 'soft_delete_scope')) {
            return (bool) $this->_model->soft_delete_scope;
        }

        return true;
    }

    /**
     * Validates logical write-column names while keeping them unquoted until
     * compilation. Write schemas intentionally allow only simple identifiers:
     * dot-qualified paths are meaningful in read clauses, not INSERT/UPDATE.
     *
     * @param array $columns
     *
     * @return array
     */
    private function normalizeWriteColumns(array $columns)
    {
        $normalized = [];

        foreach ($columns as $column) {
            if (!\is_string($column)) {
                throw new RuntimeException('Invalid SQL identifier.');
            }

            Identifier::assertSimple($column);
            if (!\in_array($column, $normalized, true)) {
                $normalized[] = $column;
            }
        }

        return $normalized;
    }

    /**
     * Builds the canonical, first-seen union schema for a set of write rows
     * and aligns every row to it. Missing input remains SQL NULL rather than
     * borrowing a value by position from another row.
     *
     * @param array $rows
     * @param array $managedTimestampColumns
     *
     * @return array{0: array, 1: array, 2: array}
     */
    private function normalizeWriteRows(array $rows, array $managedTimestampColumns = [])
    {
        $columns = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new RuntimeException('Invalid write row.');
            }

            foreach ($this->normalizeWriteColumns(array_keys($row)) as $column) {
                if (!\in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        $callerColumns       = $columns;
        $generatedTimestamps = [];
        foreach ($managedTimestampColumns as $column) {
            $this->normalizeWriteColumns([$column]);
            if (!\in_array($column, $columns, true)) {
                $columns[]                    = $column;
                $generatedTimestamps[$column] = true;
            }
        }

        $normalizedRows = [];
        foreach ($rows as $row) {
            $normalizedRow = [];
            foreach ($columns as $column) {
                if (\array_key_exists($column, $row)) {
                    $normalizedRow[] = $row[$column];
                } elseif (isset($generatedTimestamps[$column])) {
                    $normalizedRow[] = $this->currentTimestamp();
                } else {
                    $normalizedRow[] = null;
                }
            }
            $normalizedRows[] = $normalizedRow;
        }

        return [$columns, $normalizedRows, $callerColumns];
    }

    /**
     * @param array $columns
     *
     * @return array
     */
    private function compileWriteColumns(array $columns)
    {
        return array_map(fn ($column) => $this->compileWriteColumn($column), $columns);
    }

    /**
     * @param mixed $column
     *
     * @return string
     */
    private function compileWriteColumn($column)
    {
        if (!\is_string($column)) {
            throw new RuntimeException('Invalid SQL identifier.');
        }

        return Identifier::quoteQualified($column);
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private function compileWriteValue($value)
    {
        if (\is_null($value)) {
            return 'NULL';
        }

        if (\is_array($value) || \is_object($value)) {
            $value = wp_json_encode($value);
        }

        $this->bindings[] = $value;

        return $this->getValueType($value);
    }

    /**
     * Run bulk insert query
     *
     * @param array $attributes
     *
     * @return bool|array<int, Model>
     */
    private function bulkInsert($attributes)
    {
        [$columns, $rows, $callerColumns] = $this->normalizeWriteRows(
            $attributes,
            property_exists($this->_model, 'timestamps') && $this->_model->timestamps ? ['created_at'] : []
        );
        if (empty($callerColumns)) {
            return new Collection([]);
        }

        $this->bindings = [];

        $sql = 'INSERT INTO ' . $this->table;
        $sql .= ' (' . implode(', ', $this->compileWriteColumns($columns)) . ')';
        $sql .= ' VALUES ';
        $values = [];
        foreach ($rows as $row) {
            $values[] = ' ('
                . implode(
                    ', ',
                    array_map(fn ($value) => $this->compileWriteValue($value), $row)
                ) . ')';
        }

        $sql .= empty($values) ? ' default values' : ' ' . implode(',', $values);

        if ($this->raw($sql, $this->bindings) !== false) {
            $nextID       = $this->lastInsertId();
            $ids          = [];
            $affectedRows = Connection::prop('rows_affected');
            while ($affectedRows--) {
                $ids[] = $nextID++;
            }

            if (
                !empty($ids)
                && (
                    $allRows = $this->newQuery()
                        ->where($this->_model->getPrimaryKey(), $ids)
                        ->get()
                )
            ) {
                return $allRows;
            }

            return new Collection($ids);
        }

        return false;
    }

    /**
     * Prepares columns and value
     *
     * @param bool $isUpdate
     *
     * @return array
     */
    private function prepareAttributeForSaveOrUpdate($isUpdate = false)
    {
        if ($isUpdate && $this->_model->exists()) {
            $columnsToPrepare = array_keys($this->_model->getDirtyAttributes());
            $this->bindings   = [];
        } elseif ($isUpdate && !$this->_model->exists()) {
            $columnsToPrepare = array_keys($this->_model->getAttributes());
            $this->bindings   = [];
        } else {
            $columnsToPrepare = array_keys($this->_model->getAttributes());
        }

        $columnsToPrepare = $this->withoutRelationColumns($columnsToPrepare);
        $columnsToPrepare = $this->normalizeWriteColumns($columnsToPrepare);

        if (property_exists($this->_model, 'timestamps') && $this->_model->timestamps) {
            if (!$isUpdate && !\in_array('created_at', $columnsToPrepare, true)) {
                $this->_model->setAttribute('created_at', $this->currentTimestamp());
                $columnsToPrepare[] = 'created_at';
            }

            $this->_model->setAttribute('updated_at', $this->currentTimestamp());
            if (!\in_array('updated_at', $columnsToPrepare, true)) {
                $columnsToPrepare[] = 'updated_at';
            }
        }

        $attributes = $this->_model->getAttributes();
        foreach ($columnsToPrepare as $key => $column) {
            if (isset($attributes[$column])) {
                $this->bindings[] = \is_array($this->_model->{$column})
                || \is_object($this->_model->{$column})
                ? wp_json_encode($this->_model->{$column}) : $this->_model->{$column};
            } elseif (\is_null($this->_model->{$column})) {
                $this->bindings[] = null;
            } else {
                $this->bindings[] = '';
            }
        }

        $this->_method = $isUpdate ? self::UPDATE : self::INSERT;

        return $columnsToPrepare;
    }

    /**
     * Drops columns whose current value is a loaded relation (Collection/Model)
     * from a save/update write set, so lazily-read relations are never persisted
     * to a non-existent column. Re-indexes to keep bindings positionally aligned.
     *
     * @param array $columns
     *
     * @return array
     */
    private function withoutRelationColumns(array $columns)
    {
        $attributes = $this->_model->getAttributes();

        return array_values(array_filter($columns, function ($column) use ($attributes) {
            return !\array_key_exists($column, $attributes)
                || !$this->_model->isRelationValue($attributes[$column]);
        }));
    }

    /**
     * Prepares insert statement
     *
     * @return string
     */
    private function prepareInsert()
    {
        $sql = 'INSERT INTO ' . $this->table;
        $sql .= ' (' . implode(', ', $this->compileWriteColumns($this->insert)) . ')';
        $sql .= ' VALUES ('
            . implode(
                ', ',
                array_map(
                    function ($value, $key) {
                        if (\is_null($value)) {
                            unset($this->bindings[$key]);

                            return 'NULL';
                        }

                        return $this->getValueType($value);
                    },
                    $this->bindings,
                    array_keys($this->bindings)
                )
            ) . ')';

        return $sql;
    }

    /**
     * Prepares update statement
     *
     * @return string
     */
    private function prepareUpdate()
    {
        if (isset($this->_from)) {
            throw new RuntimeException('Table aliases are not supported for write queries.');
        }

        $setBindings    = $this->bindings;
        $this->bindings = [];

        $sql = 'UPDATE ' . $this->table;
        $sql .= $this->grammar()->getJoin($this);
        $sql .= ' SET ';
        $columnCount = \count($this->update);
        foreach ($this->update as $key => $column) {
            $value  = $setBindings[$key];
            $column = $this->compileWriteColumn($column);
            if (\is_null($value)) {
                $sql .= $column . ' = NULL';
            } else {
                $sql .= $column . ' = ' . $this->getValueType($value);
                $this->addBindings($value);
            }

            if ($key < $columnCount - 1) {
                $sql .= ', ';
            }
        }

        $sql .= $this->grammar()->getWhere($this);

        return $sql;
    }

    /**
     * Prepares delete statement
     *
     * @return string
     */
    private function prepareDelete()
    {
        if (isset($this->_from)) {
            throw new RuntimeException('Table aliases are not supported for write queries.');
        }

        $whereClause = $this->grammar()->getWhere($this);

        if (empty($whereClause)) {
            return '';
        }

        if (!$this->_forceDelete && property_exists($this->_model, 'soft_deletes') && $this->_model->soft_deletes) {
            $timestamp = $this->currentTimestamp();
            array_unshift($this->bindings, $timestamp);

            return 'UPDATE ' . $this->table . ' SET deleted_at = ' . $this->getValueType($timestamp) . $whereClause;
        }

        return 'DELETE FROM ' . $this->table . $whereClause;
    }

    /**
     * Executes sql query
     *
     * @param string $sql
     *
     * @return array|object|string
     */
    private function exec($sql = null)
    {
        if ($this->dispatchEvent('pre') === false) {
            return false;
        }
        if (\is_null($sql)) {
            $sql = $this->prepare($sql);
        }
        if (empty($sql)) {
            throw new RuntimeException('SQL query is empty');
        }

        $this->bindings = [];

        $result = Connection::query($sql);

        if (!empty(Connection::prop('last_error'))) {
            return false;
        }

        // Sync the auto-increment id onto the model before `created` fires, so the
        // event handler sees the new PK (Eloquent parity). A manual/composite key
        // returns insert_id 0, so the original key is left untouched.
        if ($this->_method === self::INSERT && ($insertId = $this->lastInsertId())) {
            $this->_model->setAttribute($this->_model->getPrimaryKey(), $insertId);
        }

        $this->dispatchEvent('post');

        return $this->_method === self::SELECT ? Connection::prop('last_result') : $result;
    }

    /**
     * Dispatches model event
     *
     * @param string $type pre|post
     */
    private function dispatchEvent($type)
    {
        $prefix = null;
        $suffix = null;

        if ($type === 'pre') {
            $suffix = 'ing';
        } else {
            $suffix = 'ed';
        }

        switch ($this->_method) {
            case self::INSERT:
                if (\count($this->_model->getAttributes())) {
                    $prefix = 'creat';
                }

                break;
            case self::UPDATE:
                if ($this->_model->exists()) {
                    $prefix = 'updat';
                }

                break;
            case self::DELETE:
                if ($this->_model->exists()) {
                    $prefix = 'delet';
                }

                break;
        }

        if (!\is_null($prefix)) {
            return $this->_model->fireEvent($prefix . $suffix);
        }
    }

    /**
     * Returns last id
     *
     * @return string
     */
    private function lastInsertId()
    {
        return Connection::prop('insert_id');
    }
}
