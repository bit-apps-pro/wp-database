<?php

/**
 * Provides Base Model Class.
 */

namespace BitApps\WPDatabase;

use ArrayAccess;

use BitApps\WPDatabase\Concerns\HasEvents;

use BitApps\WPDatabase\Concerns\Relations;
use DateTime;
use JsonSerializable;
use ReturnTypeWillChange;
use RuntimeException;

/**
 * Abstract class for model.
 *
 * Query-builder calls (where/select/get/with/withCount/...) are forwarded to
 * {@see QueryBuilder} through __call/__callStatic.
 *
 * IDE support: the @method tags below give autocomplete in every editor
 * (PhpStorm + VS Code/Intelephense); the @mixin lets PhpStorm Ctrl+Click jump
 * to the real definitions. For autocomplete AND Ctrl+Click that work
 * everywhere, start chains with the real static {@see Model::query()}.
 *
 * @method static QueryBuilder                  from($_from)
 * @method static Model                         getModel()
 * @method static QueryBuilder                  queryFor($_for)
 * @method static QueryBuilder                  newQuery()
 * @method static void                          addBindings($bindings)
 * @method static array                         getBindings()
 * @method static Model|array<int, Model>|false all($columns = ['*'])
 * @method static QueryBuilder                  select($columns = ['*'])
 * @method static QueryBuilder                  addSelect($columns)
 * @method static QueryBuilder                  selectRaw($column, array $bindings = [])
 * @method static Model|array<int, Model>|false get($columns = ['*'])
 * @method static Model|bool                    first()
 * @method static Model|array<int, Model>|false find($attributes)
 * @method static Model|bool                    findOne($attributes)
 * @method static QueryBuilder                  where(...$params)
 * @method static QueryBuilder                  whereIn(...$params)
 * @method static QueryBuilder                  orWhere(...$params)
 * @method static QueryBuilder                  whereRaw($sql, $bindings = [])
 * @method static QueryBuilder                  orWhereRaw($sql, $bindings = [])
 * @method static QueryBuilder                  whereNull($column)
 * @method static QueryBuilder                  whereNotNull($column)
 * @method static QueryBuilder                  whereBetween($column, $start, $end)
 * @method static QueryBuilder                  orWhereBetween($column, $start, $end)
 * @method static QueryBuilder                  when($value = null, ?callable $callback = null, ?callable $default = null)
 * @method static array                         paginate($pageNo = 0, $perPage = 10)
 * @method static QueryBuilder                  groupBy($columns)
 * @method static QueryBuilder                  having(...$params)
 * @method static QueryBuilder                  orHaving(...$params)
 * @method static QueryBuilder                  join($table, $first_column, $operator = null, $second_column = null, $type = 'INNER')
 * @method static QueryBuilder                  leftJoin($table, $first_column, $operator = null, $second_column = null)
 * @method static QueryBuilder                  rightJoin($table, $first_column, $operator = null, $second_column = null)
 * @method static QueryBuilder                  fullJoin($table, $first_column, $operator = null, $second_column = null)
 * @method static QueryBuilder                  crossJoin($table, $first_column, $operator = null, $second_column = null)
 * @method static QueryBuilder                  orderBy($column)
 * @method static QueryBuilder                  orderByRaw($query, $bindings = [])
 * @method static QueryBuilder                  asc()
 * @method static QueryBuilder                  desc()
 * @method static object|null                   raw($sql, $bindings = [])
 * @method static QueryBuilder                  take($count)
 * @method static QueryBuilder                  skip($count)
 * @method static Model|array<int, Model>|false insert($attributes = [])
 * @method static Model|bool                    update($attributes = [])
 * @method static string|bool                   destroy($ids = [])
 * @method static Model|bool                    save()
 * @method static Model|bool                    upsert(array $values, ?array $update = null)
 * @method static QueryBuilder                  with(string|array $relation, ?Closure $callback = null)
 * @method static QueryBuilder                  withPivot($columns)
 * @method static QueryBuilder                  withCount(string|array $relation)
 * @method static QueryBuilder                  withMin(string|array $relation)
 * @method static QueryBuilder                  withMax(string|array $relation)
 * @method static QueryBuilder                  withAvg(string|array $relation)
 * @method static QueryBuilder                  withSum(string|array $relation)
 * @method static QueryBuilder                  withExists(string|array $relation)
 * @method static QueryBuilder                  whereHas(string|array $relation, ?Closure $callback = null)
 * @method static QueryBuilder                  withWhereHas(string|array $relation, ?Closure $callback = null)
 * @method static int                           count()
 * @method static mixed                         max($column)
 * @method static mixed                         min($column)
 * @method static bool|string                   delete()
 * @method static string                        toSql()
 * @method static string                        prepare($sql = null)
 * @method static QueryBuilder                  withTrashed()
 * @method static QueryBuilder                  onlyTrashed()
 *
 * @mixin \BitApps\WPDatabase\QueryBuilder
 */
abstract class Model implements ArrayAccess, JsonSerializable
{
    use Relations, HasEvents;

    public const RELATE_AS_PIVOT = 'belongsToManyPivot';

    public const PIVOT_ATTRIBUTE_PREFIX = 'pivot_';

    public $timestamps = true;

    protected $table;

    protected $primaryKey;

    protected $fillable;

    protected $casts;

    protected $prefix = '';

    protected $attributes = [];

    protected $dirty = [];

    protected static $booted = [];

    private static $_instance;

    private $_tableWithoutPrefix;

    private $_relateAs;

    private $_original = [];

    private $_isExists;

    /**
     * QueryBuilder instance.
     *
     * @var QueryBuilder
     */
    private $_queryBuilder;

    /**
     * Undocumented function.
     *
     * @param mixed $attributes
     */
    public function __construct($attributes = [])
    {
        if (!property_exists($this, 'table') || !$this->table) {
            $this->_tableWithoutPrefix = ltrim(
                strtolower(
                    preg_replace(
                        '/[A-Z]([A-Z](?![a-z]))*/',
                        '_$0',
                        basename(str_replace('\\', '/', static::class))
                    )
                ),
                '_'
            ) . 's';
        } else {
            $this->_tableWithoutPrefix = $this->table;
        }

        $dbPrefix = Connection::wpPrefix();

        if ($this->prefix === '') {
            $dbPrefix = Connection::getPrefix();
        }

        $this->table = $dbPrefix . $this->prefix . $this->_tableWithoutPrefix;

        if (!isset($this->primaryKey)) {
            $this->primaryKey = 'id';
        }

        $this->bootIfNotBooted();

        if (\is_array($attributes)) {
            $this->fill($attributes);
        } else {
            $this->setAttribute($this->getPrimaryKey(), $attributes);
            $this->refresh();
        }
    }

    public function __isset($offset)
    {
        return isset($this->attributes[$offset]);
    }

    public function __get($offset)
    {
        if (property_exists($this->getQueryBuilder(), $offset)) {
            return $this->getQueryBuilder()->{$offset};
        }

        return $this->getAttribute($offset);
    }

    public function __set($offset, $value)
    {
        $this->setAttribute($offset, $value);
    }

    public function __unset($offset)
    {
        $this->unsetAttribute($offset);
    }

    public function __call($method, $parameters)
    {
        if (method_exists($this->getQueryBuilder(), $method)) {
            return \call_user_func_array([$this->getQueryBuilder(), $method], $parameters);
        }

        throw new RuntimeException('Undefined method [' . $method . '] called on Model class.');
    }

    public static function __callStatic($method, $parameters)
    {
        return (new static())->{$method}(...$parameters);
    }

    public function __clone()
    {
        $this->setExists(false);
        $this->setRelateAs('');
        $this->dirty      = null;
        $this->attributes = [];
        $this->_original  = [];
    }

    public function fill($attributes, $force = false)
    {
        foreach ($attributes as $key => $value) {
            if ($force || !isset($this->fillable) || (isset($this->fillable) && \in_array($key, $this->fillable))) {
                $this->setAttribute($key, $this->castTo($key, $value));
            }
        }
    }

    public function refresh()
    {
        if (!isset($this->attributes[$this->primaryKey])) {
            return false;
        }

        $result = $this->newQuery()->findOne([$this->primaryKey => $this->attributes[$this->primaryKey]]);

        if (!$result) {
            $this->_isExists = false;
        } else {
            $this->_isExists = true;
        }

        return $this->_isExists;
    }

    public function exists()
    {
        if (!isset($this->_isExists)) {
            $this->refresh();
        }

        return $this->_isExists;
    }

    public function setExists($status)
    {
        $this->_isExists = $status;
    }

    public function setAttribute($key, $value)
    {
        if (
            $this->_isExists
            && (
                !isset($this->_original[$key])
                || isset($this->_original[$key]) && $this->_original[$key] != $value
            )
        ) {
            $this->dirty[$key] = $value;
        }

        $this->attributes[$key] = $value;
    }

    public function unsetAttribute($key)
    {
        unset($this->attributes[$key]);
    }

    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    public function getForeignKey()
    {
        return $this->getTableWithoutPrefix() . '_' . $this->primaryKey;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getPrefix()
    {
        return $this->prefix === '' ? Connection::getPrefix() : $this->prefix;
    }

    public function getTableWithoutPrefix()
    {
        return $this->_tableWithoutPrefix;
    }

    public function setRelateAs($relation)
    {
        $this->_relateAs = $relation;

        return $this;
    }

    public function getRelateAs()
    {
        return $this->_relateAs;
    }

    public function getFillable()
    {
        if (!isset($this->fillable)) {
            $allAttributes = $this->getAttributes();
            unset(
                $allAttributes[$this->primaryKey],
                $allAttributes['created_at'],
                $allAttributes['updated_at'],
                $allAttributes['deleted_at']
            );
            $this->fillable = array_keys($allAttributes);
        }

        return $this->fillable;
    }

    public function getAttributes()
    {
        return $this->attributes;
    }

    public function getAttribute($offset)
    {
        if ($this->offsetExists($offset)) {
            return $this->attributes[$offset];
        }

        if (method_exists($this, $offset)) {
            $this->setAttribute($offset, $this->processRelatedAttribute($this->{$offset}()));

            return $this->attributes[$offset];
        }

        if (method_exists($this, 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $offset))) . 'Attribute')) {
            $this->setAttribute(
                $offset,
                $this->{'get'
                    . str_replace(' ', '', ucwords(str_replace('_', ' ', $offset))) . 'Attribute'}()
            );

            return $this->attributes[$offset];
        }
    }

    public function isDirty()
    {
        return !\is_null($this->dirty);
    }

    public function getDirtyAttributes()
    {
        return $this->dirty;
    }

    public function getOriginal()
    {
        return $this->_original;
    }

    public function getInstanceFromBuilder($result, $setAttribute = false)
    {
        if (!\is_array($result)) {
            return false;
        }

        if (\count($result) === 0) {
            return [];
        }

        $this->retrieveRelateData($this->getQueryBuilder());
        if (\count($result) == 1 && $setAttribute) {
            $this->fill((array) $result[0], true);
            $this->setExists(true);
            $this->setRelatedData($this);
            $this->setExists(true);
            $this->fireEvent('retrieved');

            return $this;
        }

        return new Collection(
            array_map(
                function ($row) {
                    $model = clone $this;
                    $model->fill((array) $row, true);
                    $this->setRelatedData($model);
                    $model->setExists(true);
                    $this->fireEvent('retrieved', $model);

                    return $model;
                },
                $result
            )
        );
    }

    public function getQueryBuilder()
    {
        return isset($this->_queryBuilder) ? $this->_queryBuilder : $this->_queryBuilder = new QueryBuilder($this);
    }

    public function newQuery()
    {
        return new QueryBuilder($this);
    }

    /**
     * Canonical, IDE-navigable entry point to the query builder.
     *
     * @return QueryBuilder
     */
    public static function query()
    {
        return (new static())->newQuery();
    }

    #[ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->attributes[$offset]);
    }

    #[ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->getAttribute($offset);
    }

    #[ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->setAttribute($offset, $value);
    }

    #[ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        $this->unsetAttribute($offset);
    }

    #[ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    public function toArray()
    {
        if (!$this->exists()) {
            return [];
        }

        return $this->attributes;
    }

    public function withCast(array $casts)
    {
        if (!isset($this->casts)) {
            $this->casts = [];
        }

        $this->casts = array_merge($this->casts, $casts);

        return $this;
    }

    /**
     * Check if the model needs to be booted and if so, do it.
     *
     * @return void
     */
    protected function bootIfNotBooted()
    {
        if (! isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;

            $this->fireEvent('booting');

            static::booting();
            static::boot();
            static::booted();

            $this->fireEvent('booted');
        }
    }

    protected static function booting()
    {
    }

    protected static function boot()
    {
    }

    protected static function booted()
    {
    }

    private static function getInstance()
    {
        if (\is_null(self::$_instance)) {
            self::$_instance = new static();
        }

        return self::$_instance;
    }

    private function castTo($column, $value)
    {
        if (\is_null($value)) {
            return $value;
        }

        if (
            !isset($this->casts)
            || (isset($this->casts) && !isset($this->casts[$column]))
            || !method_exists($this, 'castTo' . ucfirst($this->casts[$column]))
        ) {
            return $value;
        }

        return \call_user_func([$this, 'castTo' . ucfirst($this->casts[$column])], $value);
    }

    private function castToObject($value)
    {
        if (\is_string($value)) {
            return json_decode($value);
        }

        return (object) $value;
    }

    private function castToArray($value)
    {
        if (\is_string($value)) {
            return json_decode($value, true);
        }

        return (array) $value;
    }

    private function castToInt($value)
    {
        return (int) $value;
    }

    private function castToString($value)
    {
        return (string) $value;
    }

    private function castToBool($value)
    {
        return \boolval($value);
    }

    private function castToBoolean($value)
    {
        return $this->castToBool($value);
    }

    private function castToDate($value)
    {
        return DateTime::createFromFormat('Y-m-d H:i:s', $value);
    }

    private function processRelatedAttribute(QueryBuilder $attribute)
    {
        $relation = $attribute->getModel()->getRelateAs();

        if ($relation === self::RELATE_AS_PIVOT) {
            $this->applyPivotSelectAndJoin($attribute);
            $pivot    = $attribute->getModel()->getActiveRelationKey();
            $pivotRef = $attribute->getModel()->getPrefix() . $pivot['pivotTable'];
            $attribute->where(
                $pivotRef . '.' . $pivot['foreignPivotKey'],
                $this->getAttribute($pivot['parentKey'])
            );

            return $attribute->get();
        }

        $relationKey = $attribute->getModel()->getRelationalKeys()[$relation];
        $attribute->where($relationKey['foreignKey'], $this->getAttribute($relationKey['localKey']));
        if ($relation == 'oneToOne') {
            return $attribute->first();
        }

        return $attribute->get();
    }
}
