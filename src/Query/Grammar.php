<?php

namespace BitApps\WPDatabase\Query;

use BitApps\WPDatabase\QueryBuilder;

if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Compiles the SELECT side of a {@see QueryBuilder} into an SQL string.
 *
 * Stateless collaborator: every method receives the QueryBuilder first, reads
 * its state through public getters and pushes any placeholders back onto the
 * builder via {@see QueryBuilder::addBindings()}. The builder owns the bindings;
 * the grammar only produces the SQL.
 */
class Grammar
{
    /**
     * Compiles a full SELECT statement for the given builder.
     *
     * @return string
     */
    public function compileSelect(QueryBuilder $query): string
    {
        $query->resetBindings();

        $sql = 'SELECT ' . implode(',', $query->select);
        $sql .= $this->prepareRawSelect($query);
        $sql .= ' FROM ' . $query->getTable();
        $sql .= $this->getFrom($query);
        $sql .= $this->getJoin($query);
        $sql .= $this->getWhere($query);
        $sql .= $this->getGroupBy($query);
        $sql .= $this->getHaving($query);
        $sql .= $this->getOrderBy($query);
        $sql .= $this->getLimit($query);
        $sql .= $this->getOffset($query);

        return trim($sql);
    }

    /**
     * Returns the processed WHERE clause (empty string when there is none).
     *
     * @return string
     */
    public function getWhere(QueryBuilder $query)
    {
        $sql = $this->getConditions($query);
        if (empty($sql)) {
            return '';
        }

        return " WHERE {$sql}";
    }

    /**
     * Returns the SQL for the JOIN clauses.
     *
     * @return string
     */
    public function getJoin(QueryBuilder $query)
    {
        $sql   = '';
        $joins = $query->getJoins();
        if (empty($joins)) {
            return $sql;
        }

        foreach ($joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $join['table']
                . ' ON ' . $this->processConditions($query, $join['on']);
        }

        return $sql;
    }

    /**
     * Processes a list of conditions (where/having/join-on) into SQL.
     *
     * @param array       $conditions
     * @param null|string $type
     *
     * @return string
     */
    public function processConditions(QueryBuilder $query, $conditions, $type = null)
    {
        $sql = '';
        if (\is_array($conditions) && \count($conditions) > 0) {
            foreach ($conditions as $clause) {
                if (isset($clause['bool'])) {
                    $sql .= ' ' . $clause['bool'];
                } else {
                    $sql .= ' AND';
                }

                if (isset($clause['raw'])) {
                    $sql .= ' ' . $clause['raw'];
                    $query->addBindings($clause['bindings']);

                    continue;
                }

                if (isset($clause['query']) && !\is_null($type)) {
                    $clause['query']->resetBindings();
                    $sql .= ' (' . $this->getConditions($clause['query'], $type) . ')';
                    $query->addBindings($clause['query']->getBindings());

                    continue;
                }

                $sql .= $this->prepareColumnForWhere($query, $clause);
                $sql .= $this->prepareOperatorForWhere($clause);
                $sql .= $this->prepareValueForWhere($query, $clause);
            }

            $sql = $this->removeLeadingBool($sql);
        }

        return $sql;
    }

    /**
     * Returns the processed conditions for the given clause list.
     *
     * @param string $type
     *
     * @return string
     */
    public function getConditions(QueryBuilder $query, $type = 'where')
    {
        return $this->processConditions($query, $query->getClauseList($type), $type);
    }

    /**
     * Returns the SQL for the GROUP BY clause.
     *
     * @return string
     */
    public function getGroupBy(QueryBuilder $query)
    {
        $groupBy = $query->getGroupByList();
        if (empty($groupBy)) {
            return '';
        }

        return ' GROUP BY ' . implode(',', $groupBy);
    }

    /**
     * Returns the SQL for the HAVING clause.
     *
     * @return string
     */
    public function getHaving(QueryBuilder $query)
    {
        $sql = $this->getConditions($query, 'having');
        if (empty($sql)) {
            return '';
        }

        return " HAVING {$sql}";
    }

    /**
     * Returns the SQL for the ORDER BY clause.
     *
     * @return string
     */
    public function getOrderBy(QueryBuilder $query)
    {
        $sql     = '';
        $orderBy = $query->getOrderByList();
        if (empty($orderBy)) {
            return $sql;
        }

        foreach ($orderBy as $order) {
            if (isset($order['raw'])) {
                $sql .= $order['raw'] . ', ';
                $query->addBindings($order['bindings']);
            } elseif (isset($order['column'])) {
                $sql .= $order['column'] . ' ' . $order['direction'] . ', ';
            }
        }

        return ' ORDER BY ' . rtrim($sql, ', ');
    }

    /**
     * Returns the table alias fragment for the FROM clause.
     *
     * @return string|null
     */
    public function getFrom(QueryBuilder $query)
    {
        $alias = $query->getFromAlias();

        return isset($alias) ? " {$alias}" : null;
    }

    /**
     * Returns the LIMIT fragment for the query.
     *
     * @return string
     */
    public function getLimit(QueryBuilder $query)
    {
        $limit = $query->getLimitValue();

        return isset($limit) ? " LIMIT {$limit}" : '';
    }

    /**
     * Returns the OFFSET fragment for the query.
     *
     * @return string
     */
    public function getOffset(QueryBuilder $query)
    {
        $limit  = $query->getLimitValue();
        $offset = $query->getOffsetValue();

        return isset($limit) && isset($offset) ? " OFFSET {$offset}" : '';
    }

    /**
     * Compiles the raw select columns and merges their bindings.
     *
     * @return string
     */
    public function prepareRawSelect(QueryBuilder $query)
    {
        $sql = '';
        if (!empty($query->selectRaw['columns'])) {
            $sql = \count($query->select) ? ', ' : '';
            $sql .= implode(', ', $query->selectRaw['columns']);
        }

        if (!empty($query->selectRaw['bindings'])) {
            $query->addBindings($query->selectRaw['bindings']);
        }

        return $sql;
    }

    /**
     * Prepares the column part of a where clause.
     *
     * @param array $clause
     *
     * @return string|null
     */
    public function prepareColumnForWhere(QueryBuilder $query, $clause)
    {
        if (isset($clause['column'])) {
            return ' ' . $query->prepareColumnName($clause['column']);
        }
    }

    /**
     * Prepares the operator part of a where clause.
     *
     * @param array $clause
     *
     * @return string
     */
    public function prepareOperatorForWhere($clause)
    {
        $sql = '';
        if (!isset($clause['column'])) {
            return $sql;
        }

        if (isset($clause['operator'])) {
            $sql .= ' ' . $clause['operator'];
        } elseif (\is_array($clause['value'])) {
            $sql .= ' IN ';
        } elseif (\is_null($clause['value'])) {
            $sql = ' IS NULL';
        } else {
            $sql .= ' = ';
        }

        return $sql;
    }

    /**
     * Prepares the value part of a where clause, registering bindings.
     *
     * @param array $clause
     *
     * @return string
     */
    public function prepareValueForWhere(QueryBuilder $query, $clause)
    {
        $sql = '';
        if (isset($clause['secondColumn'])) {
            return ' ' . $clause['secondColumn'];
        }

        if (!isset($clause['value'])) {
            return $sql;
        }

        if (\is_array($clause['value'])) {
            $sql .= ' (';
            foreach ($clause['value'] as $value) {
                $sql .= $query->getValueType($value) . ',';
                $query->addBindings($value);
            }

            $sql = rtrim($sql, ',') . ')';
        } elseif (isset($clause['operator']) && strpos($clause['operator'], 'IS') !== false) {
            $sql .= ' ' . $clause['value'];
        } elseif (isset($clause['operator']) && strtoupper($clause['operator'] === 'LIKE')) {
            $sql .= ' %s';
            $query->addBindings($clause['value']);
        } elseif (!\is_null($clause['value'])) {
            $sql .= ' ' . $query->getValueType($clause['value']);
            $query->addBindings($clause['value']);
        }

        return $sql;
    }

    /**
     * Removes a single leading AND/OR boolean from a condition string.
     *
     * @param string $sql
     *
     * @return string
     */
    public function removeLeadingBool($sql)
    {
        return preg_replace('/and |or /i', '', $sql, 1);
    }
}
