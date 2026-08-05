<?php

namespace BitApps\WPDatabase;

use BitApps\WPDatabase\Query\Identifier;
use BitApps\WPDatabase\Query\JoinType;
use BitApps\WPDatabase\Query\RawTemplate;
use BitApps\WPDatabase\Query\SqlOperator;
use Closure;
use DateTime;
use DateTimeZone;
use Exception;
use RuntimeException;

class QueryBuilder
{
    public const UPDATE = 'Update';

    public const INSERT = 'Insert';

    public const DELETE = 'Delete';

    public const SELECT = 'Select';

    public const TIME_FORMAT = 'Y-m-d H:i:s';

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

    protected $select = [];

    protected $selectRaw = [];

    protected $insert = [];

    protected $update = [];

    protected $distinct = false;

    protected $columns = ['*'];

    protected $raw = '';

    private $_model;

    private $_from;

    private $_for;

    private $_method;

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
     * @return void
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
     * Selects column for query
     *
     * @param array $columns
     *
     * @return $this
     */
    public function select($columns = ['*'])
    {
        $this->select = !\is_array($columns) ? \func_get_args() : $columns;

        foreach ($this->select as $column) {
            $this->assertSafeIdentifier($column, true, true);
        }

        return $this;
    }

    /**
     * Adds structured columns without replacing the current projection.
     *
     * @param mixed $columns
     *
     * @return $this
     */
    public function addSelect($columns)
    {
        $columns = !\is_array($columns) ? \func_get_args() : $columns;
        foreach ($columns as $column) {
            $this->assertSafeIdentifier($column, true, true);
            $this->select[] = $column;
        }

        return $this;
    }

    /**
     * Adds a reviewed, developer-authored raw projection.
     *
     * @param mixed $column
     * @param mixed $bindings
     *
     * @return $this
     */
    public function selectRaw($column, $bindings = [])
    {
        if (!\is_string($column)) {
            throw new RuntimeException('Invalid raw select expression.');
        }

        $this->selectRaw['columns'][] = $column;
        $this->selectRaw['bindings']  = array_merge(
            isset($this->selectRaw['bindings']) ? $this->selectRaw['bindings'] : [],
            \is_array($bindings) ? $bindings : [$bindings]
        );

        return $this;
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
        $columns = isset($columns) && \is_array($columns) ? $columns : \func_get_args();
        if (empty($this->select) || $columns !== ['*']) {
            $this->select = $columns;
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
     * Get processed conditions
     *
     * @param QueryBuilder $query
     * @param string       $type
     *
     * @return string|string[]|null
     */
    public function getConditions(QueryBuilder $query, $type = 'where')
    {
        return $this->processConditions($query->{$type}, $type);
    }

    /**
     * Prepare operator for where clause
     *
     * @param array $clause
     *
     * @return void
     */
    public function prepareOperatorForWhere($clause)
    {
        $sql = '';
        if (!isset($clause['column'])) {
            return $sql;
        }

        if (isset($clause['range'])) {
            $sql .= ' ' . SqlOperator::normalizeRange('BETWEEN');
        } elseif (isset($clause['operator'])) {
            if (isset($clause['secondColumn'])) {
                $sql .= ' ' . SqlOperator::normalizeBinary($clause['operator']);
            } elseif (\is_array(isset($clause['value']) ? $clause['value'] : null)) {
                $sql .= ' ' . SqlOperator::normalizeList($clause['operator']);
            } elseif (!\array_key_exists('value', $clause) || \is_null($clause['value'])) {
                $sql .= ' ' . SqlOperator::normalizeUnary($clause['operator']);
            } else {
                $sql .= ' ' . SqlOperator::normalizeBinary($clause['operator']);
            }
        } elseif (\is_array($clause['value'])) {
            $sql .= ' ' . SqlOperator::normalizeList('IN');
        } elseif (\is_null($clause['value'])) {
            $sql = ' ' . SqlOperator::normalizeUnary('IS NULL');
        } else {
            $sql .= ' ' . SqlOperator::normalizeBinary('=');
        }

        return $sql;
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
        if (!\is_string($sql)) {
            throw new RuntimeException('Invalid raw where expression.');
        }
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
        if (!\is_string($sql)) {
            throw new RuntimeException('Invalid raw where expression.');
        }
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
        SqlOperator::normalizeList('IN');
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
        SqlOperator::normalizeUnary('IS NULL');
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
        SqlOperator::normalizeUnary('IS NOT NULL');
        $this->where[] = [
            'column'   => $column,
            'operator' => 'IS NOT NULL',
        ];

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
            'column' => $column,
            'range'  => true,
            'value'  => [$start, $end],
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
            'column' => $column,
            'range'  => true,
            'value'  => [$start, $end],
            'bool'   => 'OR',
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
        $selectedColumns = empty($this->select) ? ['*'] : $this->select;

        $totalItems = (int) $this->count();

        $offset = ($pageNo > 1) ? ($pageNo * $perPage) - $perPage : 0;

        $data = $this->take($perPage)->skip($offset)->get($selectedColumns);

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
        [$rawTable, $userAlias, $physicalTable, $reference] = $this->parseJoinTable($table);
        $on[]                                               = $this->prepareOn($reference, $firstColumn, $operator, $secondColumn, 'AND');
        $this->joins[]                                      = [
            'table'     => $physicalTable,
            'raw'       => $rawTable,
            'userAlias' => $userAlias,
            'alias'     => $reference,
            'on'        => $on,
            'type'      => JoinType::normalize($type),
        ];

        return $this;
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
        [$rawTable, $userAlias, $physicalTable, $reference] = $this->parseJoinTable($table);
        $this->assertSafeIdentifier($firstColumn);
        $this->joins[] = [
            'table'     => $physicalTable,
            'raw'       => $rawTable,
            'userAlias' => $userAlias,
            'alias'     => $reference,
            'on'        => [[
                'column'   => $firstColumn,
                'operator' => SqlOperator::normalizeBinary($operator),
                'value'    => $value,
                'bool'     => 'AND',
            ]],
            'type' => JoinType::normalize($type),
        ];

        return $this;
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

        if (!isset($this->joins[$joinIndex])) {
            throw new RuntimeException('Cannot add an ON clause before a JOIN.');
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
     * Adds an ON condition with a bound right-hand value.
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
        $joinIndex = \count($this->joins) - 1;
        if ($joinIndex < 0) {
            throw new RuntimeException('Cannot add an ON clause before a JOIN.');
        }

        $this->assertSafeIdentifier($firstColumn);
        $this->joins[$joinIndex]['on'][] = [
            'column'   => $firstColumn,
            'operator' => SqlOperator::normalizeBinary($operator),
            'value'    => $value,
            'bool'     => $this->normalizeBoolean($bool),
        ];

        return $this;
    }

    public function orOnValue($firstColumn, $operator, $value)
    {
        return $this->onValue($firstColumn, $operator, $value, 'OR');
    }

    /**
     * Adds a reviewed, developer-authored raw ON clause.
     *
     * @param mixed $sql
     * @param mixed $bindings
     * @param mixed $bool
     *
     * @return $this
     */
    public function onRaw($sql, $bindings = [], $bool = 'AND')
    {
        $joinIndex = \count($this->joins) - 1;
        if ($joinIndex < 0 || !\is_string($sql)) {
            throw new RuntimeException('Invalid raw ON clause.');
        }

        $this->joins[$joinIndex]['on'][] = [
            'raw'      => $sql,
            'bindings' => \is_array($bindings) ? $bindings : [$bindings],
            'bool'     => $this->normalizeBoolean($bool),
        ];

        return $this;
    }

    public function orOnRaw($sql, $bindings = [])
    {
        return $this->onRaw($sql, $bindings, 'OR');
    }

    /**
     * Returns order by clause sql
     *
     * @return string
     */
    public function getOrderBy()
    {
        $sql = '';
        if (empty($this->orderBy)) {
            return $sql;
        }

        foreach ($this->orderBy as $order) {
            $sql .= $this->renderIdentifier($order['column']) . ' ' . $order['direction'] . ', ';
        }

        return ' ORDER BY ' . rtrim($sql, ', ');
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
        return $this->unsafeRaw($sql, $bindings);
    }

    /**
     * Executes fully developer-controlled legacy SQL.
     *
     * @param mixed $sql
     * @param mixed $bindings
     *
     * @return mixed
     */
    public function unsafeRaw($sql, $bindings = [])
    {
        if (!\is_string($sql)) {
            throw new RuntimeException('Invalid raw SQL.');
        }

        if (!empty($bindings)) {
            $sql = Connection::prepare($sql, $bindings);
            if (!\is_string($sql) || $sql === '') {
                throw new RuntimeException('Raw SQL preparation failed.');
            }
        }

        return $this->exec($sql);
    }

    /**
     * Executes a constrained typed SQL template.
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

        if (!empty($bindings)) {
            $sql = Connection::prepare($sql, $bindings);
            if (!\is_string($sql) || $sql === '') {
                throw new RuntimeException('Raw SQL preparation failed.');
            }
        } else {
            $sql = str_replace('%%', '%', $sql);
        }

        return $this->exec($sql);
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
        $this->limit = $this->normalizeUnsignedInteger($count, 'LIMIT');

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
        $this->offset = $this->normalizeUnsignedInteger($count, 'OFFSET');

        return $this;
    }

    /**
     * Returns processed offset for query
     *
     * @return string|null
     */
    public function getOffset()
    {
        return isset($this->limit) && isset($this->offset) ? " OFFSET {$this->offset}" : '';
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
        if (\is_array(reset($attributes))) {
            return $this->bulkInsert($attributes);
        }

        if (!\is_array($attributes)) {
            throw new RuntimeException('Invalid write row.');
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
        if (!\is_array($attributes)) {
            throw new RuntimeException('Invalid write row.');
        }
        $this->normalizeWriteColumns(array_keys($attributes));
        $this->_method = self::UPDATE;
        $this->_model->fill($attributes);
        $this->update = $this->prepareAttributeForSaveOrUpdate(true);

        return $this;
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
        $this->normalizeWriteColumns(array_keys($this->_model->getAttributes()));
        $columns = $this->prepareAttributeForSaveOrUpdate($this->_model->exists());
        $pk      = $this->_model->getPrimaryKey();
        if ($this->_model->exists()) {
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

            $this->exec();

            return Connection::prop('rows_affected');
        }

        $this->insert = $columns;
        $this->exec();
        if ($insertId = $this->lastInsertId()) {
            $this->_model->setAttribute($pk, $insertId);

            return true;
        }

        return false;
    }

    /**
     * Set count for select
     *
     * @return $this
     */
    public function withCount()
    {
        $this->selectRaw('COUNT(*) as count');

        return $this;
    }

    /**
     * Get counts for current model
     *
     * @return int|null
     */
    public function count()
    {
        $this->select    = [];
        $this->selectRaw = ['columns' => ['COUNT(*) as count'], 'bindings' => []];
        $this->_method   = 'Select';
        $result          = $this->exec();
        $this->select    = [];
        $this->selectRaw = [];

        return \is_array($result) && !empty($result[0]->count) ? $result[0]->count : null;
    }

    public function max($column)
    {
        $this->assertSafeIdentifier($column);
        $this->select    = [];
        $this->selectRaw = ['columns' => ['MAX(' . $this->renderIdentifier($column) . ') as max'], 'bindings' => []];
        $this->_method   = 'Select';
        $result          = $this->exec();
        $this->select    = [];
        $this->selectRaw = [];

        return \is_array($result) && !empty($result[0]->max) ? $result[0]->max : null;
    }

    public function min($column)
    {
        $this->assertSafeIdentifier($column);
        $this->select    = [];
        $this->selectRaw = ['columns' => ['MIN(' . $this->renderIdentifier($column) . ') as min'], 'bindings' => []];
        $this->_method   = 'Select';
        $result          = $this->exec();
        $this->select    = [];
        $this->selectRaw = [];

        return \is_array($result) && !empty($result[0]->min) ? $result[0]->min : null;
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
     * Adds relation for model
     *
     * @param string|Closure $relation
     *
     * @return $this
     */
    public function with($relation)
    {
        $args            = \func_get_args();
        $relationalQuery = $this->_model->addRelation($relation);
        if ($relationalQuery && \func_num_args() === 2 && $args[1] instanceof Closure) {
            $args[1]($relationalQuery);
        }

        return $this;
    }

    /**
     * Starts transaction
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
     * @return bool
     */
    public function commit()
    {
        return Connection::query('COMMIT');
    }

    /**
     * Rollback previously execute query
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
        if (\is_null($sql) && isset($this->_method)) {
            $sql = $this->{'prepare' . $this->_method}();
        }

        if (empty($this->bindings) || strpos($sql, '%') === false) {
            return $sql;
        }

        $prepared = Connection::prepare($sql, $this->bindings);
        if (!\is_string($prepared) || $prepared === '') {
            throw new RuntimeException('SQL preparation failed.');
        }

        return $prepared;
    }

    /**
     * Renders a logical identifier in the current model/join/alias context.
     *
     * @param mixed $column
     * @param mixed $allowWildcard
     * @param mixed $allowAlias
     */
    public function renderIdentifier($column, $allowWildcard = false, $allowAlias = false)
    {
        if (!\is_string($column)) {
            throw new RuntimeException('Invalid SQL identifier.');
        }

        $alias = null;
        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', $column, $matches)) {
            if (!$allowAlias) {
                throw new RuntimeException('Invalid SQL identifier.');
            }
            $column = $matches[1];
            $alias  = $matches[2];
            Identifier::assertSimple($alias);
        }

        $segments = explode('.', $column);
        if (\count($segments) > 2) {
            throw new RuntimeException('Invalid SQL identifier.');
        }

        if (\count($segments) === 1) {
            Identifier::quoteQualified($column, $allowWildcard);
            $qualifier = isset($this->_from) ? $this->_from : $this->table;
            $rendered  = Identifier::quoteQualified($qualifier . '.' . $column, $allowWildcard);
        } else {
            Identifier::quoteQualified($column, $allowWildcard);
            $qualifier = $this->resolveQualifier($segments[0]);
            $rendered  = Identifier::quoteQualified($qualifier . '.' . $segments[1], $allowWildcard);
        }

        if ($alias !== null) {
            $rendered .= ' AS ' . Identifier::quoteAlias($alias);
        }

        return $rendered;
    }

    /**
     * Process conditions
     *
     * @param array  $conditions
     * @param string $type
     *
     * @return string
     */
    protected function processConditions($conditions, $type = null)
    {
        $sql = '';
        if (\is_array($conditions) && \count($conditions) > 0) {
            foreach ($conditions as $clause) {
                if (isset($clause['bool'])) {
                    $sql .= ' ' . $this->normalizeBoolean($clause['bool']);
                } else {
                    $sql .= ' AND';
                }

                if (isset($clause['raw'])) {
                    $sql .= ' ' . $clause['raw'];
                    $this->addBindings($clause['bindings']);

                    continue;
                }

                if (isset($clause['query']) && !\is_null($type)) {
                    $sql .= ' (' . $clause['query']->getConditions($clause['query'], $type) . ')';
                    $this->addBindings($clause['query']->getBindings());

                    continue;
                }

                if (isset($clause['value']) && \is_array($clause['value']) && empty($clause['value'])) {
                    $operator = SqlOperator::normalizeList(isset($clause['operator']) ? $clause['operator'] : 'IN');
                    $sql .= $operator === 'NOT IN' ? ' 1 = 1' : ' 0 = 1';

                    continue;
                }

                $sql .= $this->prepareColumnForWhere($clause);
                $sql .= $this->prepareOperatorForWhere($clause);
                $sql .= $this->prepareValueForWhere($clause, $this);
            }

            $sql = $this->removeLeadingBool($sql);
        }

        return $sql;
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

        $conditions['bool'] = $this->normalizeBoolean($bool);
        if ($params[0] instanceof Closure) {
            $nestedQuery        = $this->newQuery()->queryFor($type);
            $nestedQuery->joins = $this->joins;
            $nestedQuery->_from = $this->_from;
            \call_user_func($params[0], $nestedQuery);
            $conditions['query'] = $nestedQuery;
            if (isset($params[1])) {
                $conditions['bool'] = $this->normalizeBoolean($params[1]);
            }
        } elseif ($noOfParams == 2) {
            $conditions['column'] = $params[0];
            $conditions['value']  = $params[1];
        } elseif ($noOfParams == 3) {
            $conditions['column'] = $params[0];
            $conditions['value']  = $params[2];
            if (\is_array($params[2])) {
                $conditions['operator'] = SqlOperator::normalizeList($params[1]);
            } elseif (\is_null($params[2])) {
                if ($params[1] === '=' || $params[1] === '==') {
                    $conditions['operator'] = 'IS NULL';
                } elseif ($params[1] === '!=' || $params[1] === '<>') {
                    $conditions['operator'] = 'IS NOT NULL';
                } else {
                    $conditions['operator'] = SqlOperator::normalizeUnary($params[1]);
                }
            } else {
                $conditions['operator'] = SqlOperator::normalizeBinary($params[1]);
            }
        } elseif ($noOfParams == 4) {
            $conditions['column']                                     = $params[0];
            $conditions['operator']                                   = SqlOperator::normalizeBinary($params[1]);
            $conditions[$type === 'where' ? 'value' : 'secondColumn'] = $params[2];
            $conditions['bool']                                       = $this->normalizeBoolean($params[3]);
        }

        if (isset($conditions['column'])) {
            $this->assertSafeIdentifier($conditions['column']);
        }

        return $conditions;
    }

    /**
     * Removes leading and | or
     *
     * @param string $sql
     *
     * @return string
     */
    protected function removeLeadingBool($sql)
    {
        return ltrim(preg_replace('/and |or /i', '', $sql, 1));
    }

    /**
     * Returns processed sql for where clause
     *
     * @return string
     */
    protected function getWhere()
    {
        $sql = $this->getConditions($this);
        if (empty($sql)) {
            return '';
        }

        return " WHERE {$sql}";
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
     * Returns sql for group by clause
     *
     * @return string
     */
    protected function getGroupBy()
    {
        if (empty($this->groupBy)) {
            return '';
        }

        return ' GROUP BY ' . implode(',', array_map(function ($column) {
            return $this->renderIdentifier($column);
        }, $this->groupBy));
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
     * Return sql for having clause
     *
     * @return string
     */
    protected function getHaving()
    {
        $sql = $this->getConditions($this, 'having');
        if (empty($sql)) {
            return '';
        }

        return " HAVING {$sql}";
    }

    /**
     * Returns sql for join
     *
     * @return string
     */
    protected function getJoin()
    {
        $sql = '';
        if (empty($this->joins)) {
            return $sql;
        }

        foreach ($this->joins as $join) {
            $sql .= ' ' . JoinType::normalize($join['type']) . ' JOIN ' . Identifier::quoteQualified($join['table']);
            if ($join['userAlias'] !== null) {
                $sql .= ' AS ' . Identifier::quoteAlias($join['userAlias']);
            }
            $sql .= ' ON ' . $this->processConditions($join['on']);
        }

        return $sql;
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
            Identifier::assertSimple($column);
            $key          = $column;
            $column       = $this->_model->getTableWithoutPrefix() . '.' . $key;
            $secondColumn = $table . '.' . $key;
            $operator     = '=';
        }

        $this->assertSafeIdentifier($column);
        $this->assertSafeIdentifier($secondColumn);
        $operator = SqlOperator::normalizeBinary($operator);
        $bool     = $this->normalizeBoolean($bool);

        return compact('column', 'operator', 'secondColumn', 'bool');
    }

    /**
     * Returns types
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function getValueType($value)
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
     * Helper function, to get current timestamp
     *
     * @return string
     */
    protected function currentTimestamp()
    {
        if (\function_exists('wp_timezone_string')) {
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

        $dateTime = new DateTime('now', new DateTimeZone($timezoneString));

        return $dateTime->format(self::TIME_FORMAT);
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
        if (empty($attributes)) {
            return false;
        }

        $columns = [];
        foreach ($attributes as $row) {
            if (!\is_array($row)) {
                throw new RuntimeException('Invalid write row.');
            }
            foreach ($this->normalizeWriteColumns(array_keys($row)) as $column) {
                if (!\in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }
        if (empty($columns)) {
            return false;
        }

        $createdAt = property_exists($this->_model, 'timestamps') && $this->_model->timestamps;
        if ($createdAt) {
            if (!\in_array('created_at', $columns, true)) {
                $columns[] = 'created_at';
            }
        }

        $this->bindings = [];

        $sql = 'INSERT INTO ' . Identifier::quoteQualified($this->table);
        $sql .= ' (' . implode(', ', $this->compileWriteColumns($columns)) . ')';
        $sql .= ' VALUES ';
        $values = [];
        foreach ($attributes as $row) {
            if ($createdAt) {
                if (!\array_key_exists('created_at', $row)) {
                    $row['created_at'] = $this->currentTimestamp();
                }
            }

            $rowValues = [];
            foreach ($columns as $column) {
                $rowValues[] = \array_key_exists($column, $row) ? $row[$column] : null;
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
            $ids[]        = $nextID;
            $affectedRows = Connection::prop('rows_affected') - 1;
            while ($affectedRows--) {
                $ids[] = $nextID + 1;
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

            return $ids;
        }

        return false;
    }

    /**
     * Table alias for select query
     *
     * @return string
     */
    private function getFrom()
    {
        return isset($this->_from) ? ' AS ' . Identifier::quoteAlias($this->_from) : null;
    }

    /**
     * Prepare column for where clause
     *
     * @param array $clause
     *
     * @return void
     */
    private function prepareColumnForWhere($clause)
    {
        if (isset($clause['column'])) {
            return ' ' . $this->renderIdentifier($clause['column']);
        }
    }

    /**
     * Prepare value for where clause
     *
     * @param array $clause
     * @param self  $query
     *
     * @return string
     */
    private function prepareValueForWhere($clause, self $query)
    {
        $sql = '';
        if (isset($clause['secondColumn'])) {
            return ' ' . $this->renderIdentifier($clause['secondColumn']);
        }

        if (!isset($clause['value'])) {
            return $sql;
        }

        if (isset($clause['range'])) {
            $sql .= ' ' . $this->getValueType($clause['value'][0]);
            $sql .= ' AND ' . $this->getValueType($clause['value'][1]);
            $query->addBindings($clause['value'][0]);
            $query->addBindings($clause['value'][1]);
        } elseif (\is_array($clause['value'])) {
            $sql .= ' (';
            foreach ($clause['value'] as $value) {
                $sql .= $this->getValueType($value) . ',';
                $query->addBindings($value);
            }

            $sql = rtrim($sql, ',') . ')';
        } elseif (isset($clause['operator']) && strpos($clause['operator'], 'IS') === 0) {
            return $sql;
        } elseif (!\is_null($clause['value'])) {
            $sql .= ' ' . $query->getValueType($clause['value']);
            $query->addBindings($clause['value']);
        }

        return $sql;
    }

    /**
     * Returns limit part for query
     *
     * @return string|null
     */
    private function getLimit()
    {
        return isset($this->limit) ? " LIMIT {$this->limit}" : '';
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
        if ($isUpdate) {
            $columnsToPrepare = array_keys($this->_model->getDirtyAttributes());
            $this->bindings   = [];
        } else {
            $columnsToPrepare = array_keys($this->_model->getAttributes());
        }

        if (property_exists($this->_model, 'timestamps') && $this->_model->timestamps) {
            if (!$isUpdate) {
                $this->_model->setAttribute('created_at', $this->currentTimestamp());
                $columnsToPrepare[] = 'created_at';
            }

            $this->_model->setAttribute('updated_at', $this->currentTimestamp());
            $columnsToPrepare[] = 'updated_at';
        }

        $columnsToPrepare = $this->normalizeWriteColumns($columnsToPrepare);

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
     * Prepares select statement
     *
     * @return string
     */
    private function prepareSelect()
    {
        $this->bindings = [];
        $columns        = array_map(function ($column) {
            return $this->renderIdentifier($column, true, true);
        }, $this->select);
        $projection = implode(',', $columns);
        if (!empty($this->selectRaw['columns'])) {
            $projection .= $projection === '' ? '' : ', ';
            $projection .= implode(', ', $this->selectRaw['columns']);
            $this->addBindings(isset($this->selectRaw['bindings']) ? $this->selectRaw['bindings'] : []);
        }

        $sql = 'SELECT ' . $projection . ' FROM ' . Identifier::quoteQualified($this->table);
        $sql .= $this->getFrom();
        $sql .= $this->getJoin();
        $sql .= $this->getWhere($this);
        $sql .= $this->getGroupBy();
        $sql .= $this->getHaving();
        $sql .= $this->getOrderBy();
        $sql .= $this->getLimit();
        $sql .= $this->getOffset();

        return trim($sql);
    }

    /**
     * Prepares insert statement
     *
     * @return string
     */
    private function prepareInsert()
    {
        $sql = 'INSERT INTO ' . Identifier::quoteQualified($this->table);
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
        $setBindings    = array_values($this->bindings);
        $this->bindings = [];
        $sql            = 'UPDATE ' . Identifier::quoteQualified($this->table);
        $sql .= $this->getJoin();
        $sql .= ' SET ';
        $columnCount         = \count($this->update);
        $preparedSetBindings = [];
        foreach ($this->update as $key => $column) {
            // $sql .= $column . ' = ' . $this->getValueType($this->bindings[$key]);

            if (\is_null($setBindings[$key])) {
                $sql .= $this->compileWriteColumn($column) . ' = NULL';
            } else {
                $sql .= $this->compileWriteColumn($column) . ' = ' . $this->getValueType($setBindings[$key]);
                $preparedSetBindings[] = $setBindings[$key];
            }

            if ($key < $columnCount - 1) {
                $sql .= ', ';
            }
        }

        $this->bindings = array_merge($this->bindings, $preparedSetBindings);
        $sql .= $this->getWhere($this);

        return $sql;
    }

    /**
     * Prepares delete statement
     *
     * @return string
     */
    private function prepareDelete()
    {
        if (property_exists($this->_model, 'soft_deletes') && $this->_model->soft_deletes) {
            return $this->update(['deleted_at' => $this->currentTimestamp()])->prepareUpdate();
        }

        $sql = 'DELETE FROM ' . Identifier::quoteQualified($this->table);
        $sql .= $this->getWhere($this);

        return $sql;
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
        if (\is_null($sql)) {
            $sql = $this->prepare($sql);
        }

        if (\is_null($sql)) {
            throw new Exception('SQL query is null');
        }

        Connection::query($sql);

        if (!empty(Connection::prop('last_error'))) {
            return false;
        }

        return Connection::prop('last_result');
    }

    private function assertSafeIdentifier($column, $allowWildcard = false, $allowAlias = false)
    {
        if (!\is_string($column)) {
            throw new RuntimeException('Invalid SQL identifier.');
        }

        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', $column, $matches)) {
            if (!$allowAlias) {
                throw new RuntimeException('Invalid SQL identifier.');
            }
            Identifier::assertSimple($matches[2]);
            $column = $matches[1];
        }

        Identifier::quoteQualified($column, $allowWildcard);
        if (\count(explode('.', $column)) > 2) {
            throw new RuntimeException('Invalid SQL identifier.');
        }
    }

    private function resolveQualifier($qualifier)
    {
        Identifier::assertSimple($qualifier);

        $baseReference = isset($this->_from) ? $this->_from : $this->table;
        if ($qualifier    === $this->_model->getTableWithoutPrefix()
            || $qualifier === $this->table
            || (isset($this->_from) && $qualifier === $this->_from)
        ) {
            return $baseReference;
        }

        foreach ($this->joins as $join) {
            if ($qualifier    === $join['raw']
                || $qualifier === $join['table']
                || ($join['userAlias'] !== null && $qualifier === $join['userAlias'])
            ) {
                return $join['alias'];
            }
        }

        throw new RuntimeException('Unknown SQL identifier qualifier.');
    }

    /**
     * @param mixed $table
     *
     * @return array{0: string, 1: null|string, 2: string, 3: string}
     */
    private function parseJoinTable($table)
    {
        if (!\is_string($table)
            || !preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+AS\s+([A-Za-z_][A-Za-z0-9_]*))?$/i', $table, $matches)
        ) {
            throw new RuntimeException('Invalid SQL join table declaration.');
        }

        $rawTable  = $matches[1];
        $userAlias = isset($matches[2]) ? $matches[2] : null;
        Identifier::assertSimple($rawTable);
        if ($userAlias !== null) {
            Identifier::assertSimple($userAlias);
        }

        $baseTable = $this->_model->getTable();
        $baseName  = $this->_model->getTableWithoutPrefix();
        $prefix    = substr($baseTable, 0, \strlen($baseTable) - \strlen($baseName));
        $physical  = strpos($rawTable, $prefix) === 0 ? $rawTable : $prefix . $rawTable;
        Identifier::assertSimple($physical);
        $reference = $userAlias !== null ? $userAlias : $physical;

        return [$rawTable, $userAlias, $physical, $reference];
    }

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

    private function normalizeUnsignedInteger($value, $context)
    {
        if (\is_int($value)) {
            $normalized = $value;
        } elseif (\is_string($value) && preg_match('/^[0-9]+$/', $value)) {
            $normalized = (int) $value;
        } else {
            throw new RuntimeException('Invalid SQL ' . $context . ' value.');
        }

        if ($normalized < 0) {
            throw new RuntimeException('Invalid SQL ' . $context . ' value.');
        }

        return $normalized;
    }

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

    private function compileWriteColumns(array $columns)
    {
        return array_map(function ($column) {
            return $this->compileWriteColumn($column);
        }, $columns);
    }

    private function compileWriteColumn($column)
    {
        if (!\is_string($column)) {
            throw new RuntimeException('Invalid SQL identifier.');
        }

        return Identifier::quoteQualified($column);
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
