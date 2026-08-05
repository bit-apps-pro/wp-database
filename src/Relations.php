<?php

/**
 * Class For Database Relations.
 */

namespace BitApps\WPDatabase;

use BitApps\WPDatabase\Query\Identifier;
use ReflectionMethod;
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
     * Memoized framework-vs-consumer verdict per "class::method".
     */
    private static $relationMethodCache = [];

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
        $this->assertRelationKey($foreignKey);
        $this->assertRelationKey($localKey);
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
        $this->assertRelationKey($foreignKey);
        $this->assertRelationKey($localKey);
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
        $this->assertRelationKey($foreignKey);
        $this->assertRelationKey($localKey);
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

    public function addRelation($relation)
    {
        if (!\is_string($relation)) {
            throw new RuntimeException('Invalid relation name.');
        }
        Identifier::assertSimple($relation);

        $result = $this->resolveRelationQuery($relation);

        return $this->_relations[$relation] = $result;
    }

    public function getRelationalKeys()
    {
        return $this->_relationKeys;
    }

    private function resolveRelationQuery($method)
    {
        if (!method_exists($this, $method) || $this->isFrameworkModelMethod($method)) {
            throw new RuntimeException($this->undefinedRelationMessage($method));
        }

        $result = $this->{$method}();
        if (!$this->isRelationQuery($result)) {
            throw new RuntimeException($this->undefinedRelationMessage($method));
        }

        return $result;
    }

    private function isFrameworkModelMethod($method)
    {
        $cacheKey = static::class . '::' . $method;
        if (\array_key_exists($cacheKey, self::$relationMethodCache)) {
            return self::$relationMethodCache[$cacheKey];
        }

        $declaringClass = (new ReflectionMethod($this, $method))->getDeclaringClass()->getName();

        return self::$relationMethodCache[$cacheKey] = $declaringClass === Model::class;
    }

    private function isRelationQuery($result)
    {
        if (!$result instanceof QueryBuilder) {
            return false;
        }

        $model          = $result->getModel();
        $relationalKeys = $model->getRelationalKeys();
        if (empty($relationalKeys)) {
            return false;
        }

        $relateAs = $model->getRelateAs();

        return \is_string($relateAs) && isset($relationalKeys[$relateAs]);
    }

    private function undefinedRelationMessage($method)
    {
        return 'Relation [' . $method . '] is not defined on [' . static::class . '].';
    }

    private function getRelationKeys($foreignKey, $localKey)
    {
        if (!$foreignKey) {
            $foreignKey = $this->getForeignKey();
        }

        if (!$localKey) {
            $localKey = $this->getPrimaryKey();
        }

        $this->assertRelationKey($foreignKey);
        $this->assertRelationKey($localKey);

        return [$foreignKey, $localKey];
    }

    private function assertRelationKey($key)
    {
        if ($key !== null) {
            if (!\is_string($key)) {
                throw new RuntimeException('Invalid SQL identifier.');
            }
            Identifier::assertSimple($key);
        }
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

                $model->setAttribute($relationName, $data);
            }
        }
    }
}
