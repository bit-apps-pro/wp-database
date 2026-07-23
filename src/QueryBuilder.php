<?php

namespace BitApps\WPDatabase;

use BitApps\WPDatabase\Concerns\QueriesRelationships;
use BitApps\WPDatabase\Query\Grammar;

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

    public function prepareColumnName(string $column)
    {
        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $column, $matches)) {
            return $this->prepareColumnName(trim($matches[1])) . ' AS `' . trim($matches[2], " `") . '`';
        }

        if (strpos($column, '.') !== false) {
            return $column;
        }

        $table = "`{$this->table}`.";
        if ($column != '*') {
            $column = "`{$column}`";
        }

        return $table . $column;
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
            $this->select[] = $this->prepareColumnName($column);
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
        $clone            = clone $this;
        $clone->selectRaw = ['columns' => [], 'bindings' => []];
        $clone->groupBy   = [];
        $clone->having    = [];
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
            $this->select[] = $this->prepareColumnName($column);
        }

        return $this;
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
        $this->where[] = [
            'raw' => ' (' . $column . ' BETWEEN ' . $this->getValueType($start)
                . ' AND ' . $this->getValueType($end) . ')',
            'bindings' => [$start, $end],
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
        $this->where[] = [
            'raw' => ' (' . $column . ' BETWEEN ' . $this->getValueType($start)
                . ' AND ' . $this->getValueType($end) . ')',
            'bindings' => [$start, $end],
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
        $parts         = preg_split('/\s+as\s+/i', trim($table), 2);
        $rawTable      = $parts[0];
        $alias         = isset($parts[1]) ? $parts[1] : null;
        $prefixedTable = $this->_model->getTablePrefix() . $rawTable;
        $reference     = $alias !== null ? $alias : $prefixedTable;
        $tableSql      = $alias !== null ? $prefixedTable . ' as ' . $alias : $prefixedTable;

        $on[]          = $this->prepareOn($reference, $firstColumn, $operator, $secondColumn, 'AND');
        $this->joins[] = [
            'table'     => $tableSql,
            'alias'     => $reference,
            'on'        => $on,
            'type'      => $type,
            'raw'       => $rawTable,
            'prefixed'  => $prefixedTable,
            'userAlias' => $alias,
        ];

        return $this;
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
     * Rewrites a qualified column whose table part is an unprefixed name this
     * query owns (`users.id` -> `` `wp_users`.id ``). Aliases win over the map,
     * and unknown / already-physical qualifiers pass through unchanged.
     * Idempotent: a physical qualifier is never a map key.
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

        $dot = strpos($column, '.');
        if ($dot === false) {
            return $column;
        }

        $left  = trim(substr($column, 0, $dot), '`');
        $right = substr($column, $dot + 1);

        if (\in_array($left, $this->getTableAliases(), true)) {
            return $column;
        }

        $map = $this->getTableMap();

        return isset($map[$left]) ? '`' . $map[$left] . '`.' . $right : $column;
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
            $joinIndex = 0;
        }

        $table                           = $this->joins[$joinIndex]['alias'];
        $this->joins[$joinIndex]['on'][] = $this->prepareOn($table, $firstColumn, $operator, $secondColumn, $bool);

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
     * Runs raw query
     *
     * @param string $sql
     * @param array  $bindings
     *
     * @return mixed
     */
    public function raw($sql, $bindings = [])
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
        $this->_model->fill($attributes);
        if ($this->_model->exists()) {
            return $this->save();
        }

        $this->update = $this->prepareAttributeForSaveOrUpdate(true);

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

        $query            = $this->clone();
        $query->select    = [];
        $query->selectRaw = ['columns' => [], 'bindings' => []];
        $query->distinct  = false;
        $preparedColumn   = $column === '*' ? '*' : $query->prepareColumnName($column);
        $result           = $query->selectRaw($function . '(' . $preparedColumn . ') as ' . $function)->exec();

        return \is_array($result) && isset($result[0]->{$function}) ? $result[0]->{$function} : null;
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
        } elseif (!empty($this->select) || !empty($this->selectRaw)) {
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
        if (!\is_array(reset($values))) {
            $values = [$values];
        }

        if (empty($update)) {
            $update = array_keys($values[0]);
        }

        $this->bindings = [];
        $columns        = array_keys($values[0]);
        sort($columns);
        $manageTimestamps = property_exists($this->_model, 'timestamps') && $this->_model->timestamps;
        $addCreatedAt     = $manageTimestamps                            && !\in_array('created_at', $columns, true);
        $addUpdatedAt     = $manageTimestamps                            && !\in_array('updated_at', $columns, true);
        if ($addCreatedAt) {
            $columns[] = 'created_at';
        }
        if ($addUpdatedAt) {
            $columns[] = 'updated_at';
        }
        $sql = 'INSERT INTO ' . $this->table;
        $sql .= ' (' . implode(', ', $columns) . ')';

        $sql .= ' VALUES ';
        $insertAbleValues = [];
        foreach ($values as $row) {
            ksort($row);
            if ($addCreatedAt || $addUpdatedAt) {
                $now = $this->currentTimestamp();
                if ($addCreatedAt) {
                    $row['created_at'] = $now;
                }
                if ($addUpdatedAt) {
                    $row['updated_at'] = $now;
                }
            }

            $rowValues          = array_values($row);
            $insertAbleValues[] = ' ('
                . implode(
                    ', ',
                    array_map(
                        function ($value) {
                            if (\is_null($value)) {
                                return 'NULL';
                            }

                            if (\is_array($value) || \is_object($value)) {
                                $value = wp_json_encode($value);
                            }

                            $this->bindings[] = $value;

                            return $this->getValueType($value);
                        },
                        $rowValues
                    )
                ) . ')';
        }

        $sql .= empty($insertAbleValues) ? ' default values' : ' ' . implode(',', $insertAbleValues);
        $sql .= ' ON DUPLICATE KEY UPDATE ';
        if ($manageTimestamps) {
            // Never overwrite the original creation time on update; always bump updated_at.
            $update = array_diff($update, ['created_at']);
            if (!\in_array('updated_at', $update, true)) {
                $update[] = 'updated_at';
            }
        }
        $update = array_map(function ($column) {
            return $column . ' = VALUES(' . $column . ')';
        }, $update);
        $sql .= implode(', ', $update);
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
                $conditions['bool'] = $params[1];
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
            $conditions['bool']                                       = $params[3];
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
    protected function prepareOn($table, $column, $operator, $secondColumn, $bool = 'AND')
    {
        if (\is_null($operator) && \is_null($secondColumn)) {
            $secondColumn = $column;
            $operator     = '=';
        }

        // Qualify only a bare column identifier; leave constants, quoted values
        // and function calls (10, 'active', NOW()) untouched, and dotted names
        // that are already qualified.
        if (!\is_null($secondColumn) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $secondColumn)) {
            $secondColumn = $table . '.' . $secondColumn;
        }

        return compact('column', 'operator', 'secondColumn', 'bool');
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

            $timezoneString = sprintf('%s%02d:%02d', $sign, $absHour, $absMins);
        }

        return $timezoneString;
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
     * object value is JSON-encoded. Having clauses are left untouched.
     *
     * @param array  $conditions
     * @param string $type
     *
     * @return array
     */
    private function normalizeConditions(array $conditions, $type)
    {
        if ($type !== 'where' || !\array_key_exists('value', $conditions)) {
            return $conditions;
        }

        $value = $conditions['value'];
        $bool  = isset($conditions['bool']) ? $conditions['bool'] : 'AND';

        if (\is_array($value)) {
            if ($value === []) {
                return ['bool' => $bool, 'raw' => '0 = 1', 'bindings' => []];
            }

            $conditions['value'] = $this->sanitizeInValues($value);

            return $conditions;
        }

        if (\is_null($value)) {
            if (isset($conditions['operator'])) {
                unset($conditions['value']);
                $conditions['operator'] = $this->nullOperator($conditions['operator']);
            }

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
        $negations = ['!=', '<>', 'NOT', 'IS NOT', 'IS NOT NULL'];

        return \in_array($operator, $negations, true) ? 'IS NOT NULL' : 'IS NULL';
    }

    /**
     * Guards an ORDER BY / GROUP BY column against injection: only a plain,
     * qualified (table.column) or back-ticked identifier is accepted. Raw
     * expressions must go through orderByRaw(). Valid identifiers are not
     * re-rendered, so the emitted SQL stays byte-identical.
     *
     * @param mixed $column
     *
     * @return void
     */
    private function assertSafeIdentifier($column)
    {
        if (!\is_string($column) || !preg_match('/^[A-Za-z0-9_.`]+$/', $column)) {
            throw new RuntimeException('Unsafe column passed to order/group by clause.');
        }
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
     * Run bulk insert query
     *
     * @param array $attributes
     *
     * @return bool|array<int, Model>
     */
    private function bulkInsert($attributes)
    {
        $firstRow = reset($attributes);
        if (empty($firstRow)) {
            return new Collection([]);
        }

        ksort($firstRow);
        $columns   = array_keys($firstRow);
        $createdAt = property_exists($this->_model, 'timestamps') && $this->_model->timestamps;
        if ($createdAt) {
            $columns[] = 'created_at';
        }

        $this->bindings = [];

        $sql = 'INSERT INTO ' . $this->table;
        $sql .= ' (' . implode(', ', $columns) . ')';
        $sql .= ' VALUES ';
        $values = [];
        foreach ($attributes as $row) {
            if ($createdAt) {
                $row['created_at'] = $this->currentTimestamp();
            }

            // Align each row to the header columns by key so rows with differing
            // keys are not positionally misaligned (absent column => NULL).
            $rowValues = [];
            foreach ($columns as $column) {
                $rowValues[] = isset($row[$column]) ? $row[$column] : null;
            }

            $values[] = ' ('
                . implode(
                    ', ',
                    array_map(
                        function ($value) {
                            if (\is_null($value)) {
                                return 'NULL';
                            }

                            if (\is_array($value) || \is_object($value)) {
                                $value = wp_json_encode($value);
                            }

                            $this->bindings[] = $value;

                            return $this->getValueType($value);
                        },
                        $rowValues
                    )
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

        if (property_exists($this->_model, 'timestamps') && $this->_model->timestamps) {
            if (!$isUpdate) {
                $this->_model->setAttribute('created_at', $this->currentTimestamp());
                $columnsToPrepare[] = 'created_at';
            }

            $this->_model->setAttribute('updated_at', $this->currentTimestamp());
            $columnsToPrepare[] = 'updated_at';
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
        $sql .= ' (' . implode(', ', $this->insert) . ')';
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
        $sql = 'UPDATE ' . $this->table;
        $sql .= $this->grammar()->getJoin($this);
        $sql .= ' SET ';
        $columnCount = \count($this->update);
        foreach ($this->update as $key => $column) {
            if (\is_null($this->bindings[$key])) {
                $sql .= $column . ' = NULL';
                unset($this->bindings[$key]);
            } else {
                $sql .= $column . ' = ' . $this->getValueType($this->bindings[$key]);
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
