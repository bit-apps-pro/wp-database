<?php

/**
 * Relation querying for the QueryBuilder: eager loading, existence filters and
 * relation aggregates. Kept separate from core query building (SRP).
 */

namespace BitApps\WPDatabase;

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
     * @param string|array $relation
     * @param mixed        $column
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

        if ($column !== '*') {
            $column = $this->prepareColumnName($column);
        }

        foreach ($this->_model->prepareRelation((array) $relation) as $relationName => $relationalQuery) {
            [$name, $alias] = $this->_model->prepareRelationName($relationName);
            if (\is_null($alias)) {
                $alias = strtolower($name . '_' . $function);
            }

            $this->correlate($relationalQuery);

            if ($function === 'exists') {
                $query = $relationalQuery->select($column)->prepare();
                $this->selectRaw("exists({$query}) as `{$alias}`")->withCast([$alias => 'bool']);
            } else {
                $query = $relationalQuery->selectRaw(sprintf('%s(%s)', $function, $column))->prepare();
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
