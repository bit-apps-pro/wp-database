<?php

/**
 * Class For Database Relations.
 */

namespace BitApps\WPDatabase;

use Closure;

if (!\defined('ABSPATH')) {
    exit;
}

trait Relations
{
    private $_relations = [];

    private $_relatedData = [];

    private $_relationKeys = [];

    /**
     * Undocumented function.
     *
     * @param string     $table
     * @param string     $key
     * @param string     $type
     * @param mixed      $model
     * @param null|mixed $foreignKey
     * @param null|mixed $localKey
     *
     * @return QueryBuilder
     */
    public function belongsTo($model, $foreignKey = null, $localKey = null)
    {
        $relationKeys = $this->getRelationKeys($foreignKey, $localKey);
        $foreignKey   = $relationKeys[0];
        $localKey     = $relationKeys[1];

        return $this->newBelongsTo($model, $foreignKey, $localKey);
    }

    public function newBelongsTo($model, $foreignKey = null, $localKey = null)
    {
        $model = new $model();
        $model->setRelateAs('oneToOne');
        $model->_relationKeys['oneToOne'] = [
            'foreignKey' => $foreignKey,
            'localKey'   => $localKey,
        ];

        return $model->newQuery();
    }

    public function hasOne($model, $foreignKey = null, $localKey = null)
    {
        return $this->belongsTo($model, $foreignKey, $localKey);
    }

    public function hasMany($model, $foreignKey = null, $localKey = null)
    {
        $relationKeys = $this->getRelationKeys($foreignKey, $localKey);
        $foreignKey   = $relationKeys[0];
        $localKey     = $relationKeys[1];

        return $this->newHasMany($model, $foreignKey, $localKey);
    }

    public function newHasMany($model, $foreignKey = null, $localKey = null)
    {
        $model = new $model();
        $model->setRelateAs('hasMany');
        $model->_relationKeys['hasMany'] = [
            'foreignKey' => $foreignKey,
            'localKey'   => $localKey,
        ];

        return $model->newQuery();
    }

    public function belongsToMany($model, $foreignKey = null, $localKey = null)
    {
        $relationKeys = $this->getRelationKeys($foreignKey, $localKey);
        $foreignKey   = $relationKeys[0];
        $localKey     = $relationKeys[1];

        return $this->newBelongsToMany($model, $foreignKey, $localKey);
    }

    public function newBelongsToMany($model, $foreignKey = null, $localKey = null)
    {
        $model = new $model();
        $model->setRelateAs('belongsToMany');
        $model->_relationKeys['belongsToMany'] = [
            'foreignKey' => $foreignKey,
            'localKey'   => $localKey,
        ];

        return $model->newQuery();
    }

    /**
     * Returns list of query of relations
     *
     * @return array<string, QueryBuilder>
     */
    public function getRelations()
    {
        return $this->_relations;
    }

    public function addRelation(array $relation)
    {
        $this->_relations = array_merge($this->_relations, $relation);
    }

    public function getRelationalKeys()
    {
        return $this->_relationKeys;
    }

    /**
     * Prepares relations to query
     *
     * @param array $relations
     *
     * @return array<int, QueryBuilder>
     */
    public function prepareRelation(array $relations): array
    {
        $preparedRelation = [];
        foreach ($relations as $key => $value) {
            if (\is_int($key) && \is_string($value) && ($method = explode(' ', $value)[0]) && method_exists($this, $method)) {
                $preparedRelation[$value] = $this->{$method}();
                unset($relations[$key]);
            }
        }

        foreach ($relations as $key => $value) {
            if (\is_string($key) && ($method = explode(' ', $key)[0]) && method_exists($this, $method)) {
                $preparedRelation[$key] = $this->{$method}();
                if ($value instanceof Closure) {
                    $value($preparedRelation[$key]);
                }
            }
        }

        return $preparedRelation;
    }

    /**
     * Adds relation for model
     *
     * @param string|array $relation
     * @param Closure      $callback
     *
     * @return $this
     */
    public function with($relation, $callback = null)
    {
        $relations = [];
        if ($callback instanceof Closure) {
            $relations = $this->prepareRelation([$relation => $callback]);
        } else {
            $relations = $this->prepareRelation(
                \is_string($relation) ? [$relation => null] : \func_get_args()
            );
        }

        $this->addRelation($relations);

        return $this;
    }

    /**
     * Adds count as sub query in this query
     *
     * @param string
     * @param mixed $relation
     *
     * @return $this
     */
    public function withCount($relation)
    {
        return $this->withAggregate(\is_array($relation) ? $relation : \func_get_args() , '*', 'count');
    }

    /**
     * Adds aggregate sub query to this query
     *
     * @param array $relation
     * @param mixed $column
     * @param mixed $function
     *
     * @return $this
     */
    public function withAggregate($relation, $column, $function)
    {
        if (empty($relation)) {
            return $this;
        }

        if (empty($this->getQueryBuilder()->select)) {
            $this->getQueryBuilder()->select = ["`{$this->getTable()}`.*"];
        }

        if ($column !== '*') {
            $column = $this->getQueryBuilder()->prepareColumnName($column);
        }
        foreach ($this->prepareRelation(\is_array($relation) ? $relation : [$relation]) as $relationName => $relationalQuery) {
            [$name, $alias] = $this->prepareRelationName($relationName);
            if (\is_null($alias)) {
                $alias = strtolower($name . '_' . $function);
            }

            $relationKey = $relationalQuery->getModel()
                ->getRelationalKeys()[$relationalQuery->getModel()->getRelateAs()];
            $relationalQuery->whereRaw($this->getQueryBuilder()->prepareColumnName($relationKey['localKey']) . '=' . $relationalQuery->prepareColumnName($relationKey['foreignKey']));

            if ($function === 'exists') {
                $query = $relationalQuery->select($column)->prepare();
                $this->getQueryBuilder()->selectRaw("exists({$query}) as `{$alias}`")->withCast([$alias => 'bool']);
            } else {
                $query = $relationalQuery->selectRaw(\sprintf('%s(%s)', $function, $column))->prepare();
                $this->getQueryBuilder()->selectRaw("({$query}) as `{$alias}`");
            }
        }

        return $this;
    }

    public function whereHas($relation, $callback = null)
    {
        $relations = [];
        if ($callback instanceof Closure) {
            $relations = $this->prepareRelation([$relation => $callback]);
        } else {
            $relations = $this->prepareRelation(
                \is_string($relation) ? [$relation => null] : \func_get_args()
            );
        }

        foreach ($relations as $relationName => $relationalQuery) {
            $relationKey = $relationalQuery->getModel()
                ->getRelationalKeys()[$relationalQuery->getModel()->getRelateAs()];
            $relationalQuery->whereRaw($this->getQueryBuilder()->prepareColumnName($relationKey['localKey']) . '=' . $relationalQuery->prepareColumnName($relationKey['foreignKey']));

            $query = $relationalQuery->select('*')->prepare();
            $this->getQueryBuilder()->whereRaw("exists({$query})");
        }

        return $this;
    }

    public function withWhereHas($relation, $callback = null)
    {
        $relations = [];
        if ($callback instanceof Closure) {
            $relations = $this->prepareRelation([$relation => $callback]);
        } else {
            $relations = $this->prepareRelation(
                \is_string($relation) ? [$relation => null] : \func_get_args()
            );
        }

        foreach ($relations as $relationName => $relationalQuery) {
            $relationKey = $relationalQuery->getModel()
                ->getRelationalKeys()[$relationalQuery->getModel()->getRelateAs()];
            $newQuery = $relationalQuery->newQuery();
            $newQuery->whereRaw($this->getQueryBuilder()->prepareColumnName($relationKey['localKey']) . '=' . $newQuery->prepareColumnName($relationKey['foreignKey']));

            $query = $newQuery->select('*')->prepare();
            $this->getQueryBuilder()->whereRaw("exists({$query})");
        }

        $this->addRelation($relations);

        return $this;
    }

    public function prepareRelationName(string $relationName): array
    {
        $name      = $relationName;
        $alias     = null;
        $nameChunk = explode(' ', $relationName);

        if (\count($nameChunk) === 3 && strtolower($nameChunk[1]) === 'as') {
            $alias = $nameChunk[2];
        }

        return [$name, $alias];
    }

    private function getRelationKeys($foreignKey, $localKey)
    {
        if (!$foreignKey) {
            $foreignKey = $this->getForeignKey();
        }

        if (!$localKey) {
            $localKey = $this->getPrimaryKey();
        }

        return [$foreignKey, $localKey];
    }

    private function retrieveRelateData(QueryBuilder $query)
    {
        $relations = $this->getRelations();

        if (\count($relations) > 0) {
            foreach ($relations as $relationName => $relationQuery) {
                $parentQuery = clone $query;
                $relationKey = $relationQuery->getModel()
                    ->getRelationalKeys()[$relationQuery->getModel()->getRelateAs()];

                $relationQuery->whereRaw(
                    $relationKey['foreignKey']
                        . ' IN ( SELECT * FROM ('
                        . $parentQuery->select($relationKey['localKey'])->prepare()
                        . ') AS subquery )'
                );

                $relatedModels = $relationQuery->get();

                if ($relatedModels) {
                    foreach ($relatedModels as $relatedModel) {
                        $this->_relatedData[$relationName][$relatedModel->getAttribute($relationKey['foreignKey'])][]
                            = $relatedModel;
                    }
                }
            }
        }
    }

    private function setRelatedData(Model $model)
    {
        $relations = $this->getRelations();
        if (\count($relations) > 0) {
            foreach ($relations as $relationName => $relationQuery) {
                $relationKey = $relationQuery->getModel()
                    ->getRelationalKeys()[$relationQuery->getModel()->getRelateAs()];

                $data = isset(
                    $this->_relatedData[$relationName][$model->getAttribute($relationKey['localKey'])]
                ) ? $this->_relatedData[$relationName][$model->getAttribute($relationKey['localKey'])] : null;

                if ($relationQuery->getModel()->getRelateAs() === 'oneToOne' && is_countable($data) && \count($data)) {
                    $data = $data[0];
                }

                [$name, $alias] = $this->prepareRelationName($relationName);

                $model->setAttribute(\is_null($alias) ? $name : $alias, $data);
            }
        }
    }
}
