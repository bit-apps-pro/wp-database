<?php

/**
 * Class For Database Relations.
 */

namespace BitApps\WPDatabase\Concerns;

use BitApps\WPDatabase\Model;
use BitApps\WPDatabase\QueryBuilder;
use Closure;
use RuntimeException;

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

    public function belongsToMany(
        $model,
        $pivotTable = null,
        $foreignPivotKey = null,
        $relatedPivotKey = null,
        $parentKey = null,
        $relatedKey = null
    ) {
        if ($pivotTable === null) {
            $relationKeys = $this->getRelationKeys($foreignPivotKey, $relatedPivotKey);

            return $this->newBelongsToMany($model, $relationKeys[0], $relationKeys[1]);
        }

        return $this->newBelongsToManyPivot(
            $model,
            $pivotTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey
        );
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

    public function newBelongsToManyPivot(
        $model,
        $pivotTable,
        $foreignPivotKey = null,
        $relatedPivotKey = null,
        $parentKey = null,
        $relatedKey = null
    ) {
        $related = new $model();

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $related->getForeignKey();
        $parentKey       = $parentKey ?: $this->getPrimaryKey();
        $relatedKey      = $relatedKey ?: $related->getPrimaryKey();

        $related->setRelateAs(Model::RELATE_AS_PIVOT);
        $related->_relationKeys[Model::RELATE_AS_PIVOT] = [
            'pivotTable'      => $pivotTable,
            'foreignPivotKey' => $foreignPivotKey,
            'relatedPivotKey' => $relatedPivotKey,
            'parentKey'       => $parentKey,
            'relatedKey'      => $relatedKey,
            'pivotColumns'    => [],
        ];

        return $related->newQuery();
    }

    public function addPivotColumns(array $columns)
    {
        if (!isset($this->_relationKeys[Model::RELATE_AS_PIVOT])) {
            throw new RuntimeException('withPivot() is only valid on a pivot belongsToMany relation.');
        }

        $this->_relationKeys[Model::RELATE_AS_PIVOT]['pivotColumns'] = array_merge(
            $this->_relationKeys[Model::RELATE_AS_PIVOT]['pivotColumns'],
            $columns
        );
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
     * Returns the {localKey, foreignKey} pair for this model's active relation.
     *
     * @return array
     */
    public function getActiveRelationKey()
    {
        return $this->getRelationalKeys()[$this->getRelateAs()];
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
                if ($relationQuery->getModel()->getRelateAs() === Model::RELATE_AS_PIVOT) {
                    $this->retrievePivotRelateData($relationName, $relationQuery, $query);

                    continue;
                }

                $relationKey = $relationQuery->getModel()->getActiveRelationKey();

                $relationQuery->whereRaw(
                    $relationKey['foreignKey']
                        . ' IN ( SELECT * FROM ('
                        . $query->prepareKeySubquery($relationKey['localKey'])
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

    private function retrievePivotRelateData($relationName, QueryBuilder $relationQuery, QueryBuilder $query)
    {
        [$pivot, $pivotRef, $bucketAlias] = $this->applyPivotSelectAndJoin($relationQuery);

        $relationQuery->whereRaw(
            $pivotRef . '.' . $pivot['foreignPivotKey']
                . ' IN ( SELECT * FROM ('
                . $query->prepareKeySubquery($pivot['parentKey'])
                . ') AS subquery )'
        );

        $relatedModels = $relationQuery->get();

        if ($relatedModels) {
            foreach ($relatedModels as $relatedModel) {
                $this->_relatedData[$relationName][$relatedModel->getAttribute($bucketAlias)][] = $relatedModel;
            }
        }
    }

    /**
     * Selects related.* plus the aliased pivot link column, joins the pivot
     * table on the related key, and appends any withPivot() columns. Returns
     * [pivot metadata, prefixed pivot-table reference, bucket alias] so callers
     * build their predicate without recomputing them.
     *
     * @return array
     */
    private function applyPivotSelectAndJoin(QueryBuilder $relationQuery)
    {
        $model    = $relationQuery->getModel();
        $pivot    = $model->getActiveRelationKey();
        $pivotRef = $model->getTablePrefix() . $pivot['pivotTable'];
        $alias    = Model::PIVOT_ATTRIBUTE_PREFIX . $pivot['foreignPivotKey'];

        $relationQuery->select(['*']);
        $relationQuery->join(
            $pivot['pivotTable'],
            $pivotRef . '.' . $pivot['relatedPivotKey'],
            '=',
            $relationQuery->getTable() . '.' . $pivot['relatedKey']
        );
        $relationQuery->selectRaw($pivotRef . '.' . $pivot['foreignPivotKey'] . ' as `' . $alias . '`');

        foreach ($pivot['pivotColumns'] as $column) {
            $relationQuery->selectRaw(
                $pivotRef . '.' . $column . ' as `' . Model::PIVOT_ATTRIBUTE_PREFIX . $column . '`'
            );
        }

        return [$pivot, $pivotRef, $alias];
    }

    private function setRelatedData(Model $model)
    {
        $relations = $this->getRelations();
        if (\count($relations) > 0) {
            foreach ($relations as $relationName => $relationQuery) {
                if ($relationQuery->getModel()->getRelateAs() === Model::RELATE_AS_PIVOT) {
                    $this->setPivotRelatedData($model, $relationName, $relationQuery->getModel());

                    continue;
                }

                $relationKey = $relationQuery->getModel()->getActiveRelationKey();

                // empty relations store [] (not null) so accessing them returns the
                // resolved-empty result instead of falling through to a lazy re-query (N+1).
                $data = isset(
                    $this->_relatedData[$relationName][$model->getAttribute($relationKey['localKey'])]
                ) ? $this->_relatedData[$relationName][$model->getAttribute($relationKey['localKey'])] : [];

                if ($relationQuery->getModel()->getRelateAs() === 'oneToOne' && is_countable($data) && \count($data)) {
                    $data = $data[0];
                }

                [$name, $alias] = $this->prepareRelationName($relationName);

                $model->setAttribute(\is_null($alias) ? $name : $alias, $data);
            }
        }
    }

    private function setPivotRelatedData(Model $model, $relationName, Model $relatedModel)
    {
        $pivot = $relatedModel->getActiveRelationKey();
        $key   = $model->getAttribute($pivot['parentKey']);
        $data  = isset($this->_relatedData[$relationName][$key])
            ? $this->_relatedData[$relationName][$key]
            : [];

        [$name, $alias] = $this->prepareRelationName($relationName);
        $model->setAttribute(\is_null($alias) ? $name : $alias, $data);
    }
}
