<?php

/**
 * Relation querying for the QueryBuilder: eager loading, existence filters and
 * relation aggregates. Kept separate from core query building (SRP).
 */

namespace BitApps\WPDatabase\Concerns;

use BitApps\WPDatabase\QueryBuilder;
use Closure;

if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Partial of {@see QueryBuilder} (relies on its $select/$selectRaw state, the
 * bound $_model, and selectRaw()/whereRaw()/prepareColumnName()).
 *
 * @mixin \BitApps\WPDatabase\QueryBuilder
 */
trait QueriesRelationships
{
    /**
     * Eager loads the given relation(s) onto the bound model.
     *
     * @param string|array $relation
     * @param null|Closure $callback
     *
     * @return $this
     */
    public function with($relation, $callback = null)
    {
        $this->_model->addRelation($this->resolveRelations($relation, $callback));

        return $this;
    }

    /**
     * Adds a relation count sub query to this query.
     *
     * @param string|array $relation
     *
     * @return $this
     */
    public function withCount($relation)
    {
        return $this->withAggregate($relation, '*', 'count');
    }

    /**
     * Adds a relation min sub query to this query.
     *
     * @param string|array $relation
     *
     * @return $this
     */
    public function withMin($relation)
    {
        return $this->withAggregate($relation, '*', 'min');
    }

    /**
     * Adds a relation max sub query to this query.
     *
     * @param string|array $relation
     *
     * @return $this
     */
    public function withMax($relation)
    {
        return $this->withAggregate($relation, '*', 'max');
    }

    /**
     * Adds a relation avg sub query to this query.
     *
     * @param string|array $relation
     *
     * @return $this
     */
    public function withAvg($relation)
    {
        return $this->withAggregate($relation, '*', 'avg');
    }

    /**
     * Adds a relation sum sub query to this query.
     *
     * @param string|array $relation
     *
     * @return $this
     */
    public function withSum($relation)
    {
        return $this->withAggregate($relation, '*', 'sum');
    }

    /**
     * Adds a relation exists sub query to this query.
     *
     * @param string|array $relation
     *
     * @return $this
     */
    public function withExists($relation)
    {
        return $this->withAggregate($relation, '*', 'exists');
    }

    /**
     * Adds an aggregate sub query for the given relation(s) to this query.
     *
     * The aggregated column may be expressed inline as `relation.column`
     * (e.g. `withSum('posts.amount')`); otherwise the `$column` fallback is used.
     *
     * @param string|array $relation
     * @param mixed        $column   fallback column when a token carries none
     * @param string       $function
     *
     * @return $this
     */
    public function withAggregate($relation, $column, $function)
    {
        if (empty($relation)) {
            return $this;
        }

        if (empty($this->select)) {
            $this->select = ["`{$this->_model->getTable()}`.*"];
        }

        [$relations, $columns] = $this->normalizeAggregateRelations((array) $relation, $column);

        foreach ($this->_model->prepareRelation($relations) as $relationName => $relationalQuery) {
            [$name, $alias] = $this->_model->prepareRelationName($relationName);
            if (\is_null($alias)) {
                $alias = strtolower($name . '_' . $function);
            }

            $aggregateColumn = $columns[$relationName];

            $this->correlate($relationalQuery);

            if ($function === 'exists') {
                $query = $relationalQuery->select($aggregateColumn)->prepare();
                $this->selectRaw("exists({$query}) as `{$alias}`")->withCast([$alias => 'bool']);
            } else {
                if ($aggregateColumn !== '*') {
                    $aggregateColumn = $relationalQuery->prepareColumnName($aggregateColumn);
                }
                $query = $relationalQuery->selectRaw(sprintf('%s(%s)', $function, $aggregateColumn))->prepare();
                $this->selectRaw("({$query}) as `{$alias}`");
            }
        }

        return $this;
    }

    /**
     * Filters the query to models having the given relation.
     *
     * @param string|array $relation
     * @param null|Closure $callback
     *
     * @return $this
     */
    public function whereHas($relation, $callback = null)
    {
        foreach ($this->resolveRelations($relation, $callback) as $relationalQuery) {
            $this->correlate($relationalQuery);
            $this->whereRaw('exists(' . $relationalQuery->select('*')->prepare() . ')');
        }

        return $this;
    }

    /**
     * Filters by relation existence and eager loads the same relation.
     *
     * @param string|array $relation
     * @param null|Closure $callback
     *
     * @return $this
     */
    public function withWhereHas($relation, $callback = null)
    {
        $relations = $this->resolveRelations($relation, $callback);

        foreach ($relations as $relationalQuery) {
            $existsQuery = $this->correlate($relationalQuery->newQuery());
            $this->whereRaw('exists(' . $existsQuery->select('*')->prepare() . ')');
        }

        $this->_model->addRelation($relations);

        return $this;
    }

    /**
     * Splits relation tokens into the relation expression (kept for resolution,
     * including any `as <alias>`) and the column to aggregate, preserving the
     * int-keyed-string vs string-keyed-closure shapes that prepareRelation expects.
     *
     * @param array $relation
     * @param mixed $defaultColumn
     *
     * @return array{0: array, 1: array<string, mixed>}
     */
    private function normalizeAggregateRelations(array $relation, $defaultColumn)
    {
        $relations = [];
        $columns   = [];

        foreach ($relation as $key => $value) {
            $positional              = \is_int($key) && \is_string($value);
            [$relationExpr, $column] = $this->splitAggregateColumn($positional ? $value : (string) $key, $defaultColumn);

            $columns[$relationExpr] = $column;
            if ($positional) {
                $relations[] = $relationExpr;
            } else {
                $relations[$relationExpr] = $value;
            }
        }

        return [$relations, $columns];
    }

    /**
     * Parses a `relation[.column][ as alias]` token.
     *
     * @param string $expr
     * @param mixed  $defaultColumn used when the token carries no `.column`
     *
     * @return array{0: string, 1: mixed} [relationExpr, column]
     */
    private function splitAggregateColumn($expr, $defaultColumn)
    {
        $alias = '';
        $body  = $expr;
        if (preg_match('/^(.*?)\s+as\s+(.*)$/i', $expr, $matches)) {
            $body  = $matches[1];
            $alias = trim($matches[2]);
        }

        if (strpos($body, '.') !== false) {
            [$relation, $column] = explode('.', $body, 2);
            $column              = trim($column);
        } else {
            $relation = $body;
            $column   = $defaultColumn;
        }

        $relation     = trim($relation);
        $relationExpr = $alias !== '' ? $relation . ' as ' . $alias : $relation;

        return [$relationExpr, $column];
    }

    /**
     * Resolves relation names (and an optional constraint) into prepared
     * relational queries.
     *
     * @param string|array $relation
     * @param null|Closure $callback
     *
     * @return array<string, QueryBuilder>
     */
    private function resolveRelations($relation, $callback)
    {
        if (\is_string($relation)) {
            return $this->_model->prepareRelation([$relation => $callback]);
        }

        return $this->_model->prepareRelation((array) $relation);
    }

    /**
     * Adds the parent/child key correlation predicate to a relational query.
     *
     * @return QueryBuilder
     */
    private function correlate(QueryBuilder $relationalQuery)
    {
        $relationKey = $relationalQuery->getModel()->getActiveRelationKey();

        $relationalQuery->whereRaw(
            $this->prepareColumnName($relationKey['localKey'])
            . '=' . $relationalQuery->prepareColumnName($relationKey['foreignKey'])
        );

        return $relationalQuery;
    }
}
