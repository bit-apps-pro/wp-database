<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for joins, correlated sub-queries, when(), constrained
 * eager loading and the update path. Asserts the compiled SQL AND the binding
 * array order — the binding order is the real risk surface of the Query\Grammar
 * extraction (selectRaw-before-where, nested-closure flattening).
 */
final class QueryFeaturesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    // --- Select --------------------------------------------------------------

    public function testSelectColumnAliasQualifiesColumnNotWholeExpression(): void
    {
        $sql = (new User())->select(['id', 'name AS n'])->toSql();

        // the column is qualified/back-ticked, the alias kept separate
        $this->assertStringContainsString('`name` AS `n`', $sql);
        // the whole "name AS n" must NOT be treated as one column name
        $this->assertStringNotContainsString('`name AS n`', $sql);
    }

    // --- Joins ---------------------------------------------------------------

    public function testInnerJoinCompilesWithOnClause(): void
    {
        $sql = (new User())->join('posts', 'posts.user_id', '=', 'users.id')->toSql();

        $this->assertStringContainsString('INNER JOIN', $sql);
        // Unprefixed model/join table names in ON columns resolve to physical.
        $this->assertStringContainsString('`wp_posts`.user_id', $sql);
        $this->assertStringContainsString('`wp_users`.id', $sql);
    }

    public function testLeftJoinThenWhereKeepsBindingsInOrder(): void
    {
        $qb = (new User())->leftJoin('posts', 'posts.user_id', '=', 'users.id')->where('users.id', '>', 5);

        $sql = $qb->toSql();

        $this->assertStringContainsString('LEFT JOIN', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertSame([5], $qb->getBindings(), 'join carries no binding; only the where value');
    }

    // --- Sub-queries ---------------------------------------------------------

    public function testNestedClosureGroupsAndFlattensBindingsInOrder(): void
    {
        $qb = User::where('a', 1)->where(function ($q) {
            $q->where('b', 2)->orWhere('c', 3);
        });

        $sql = $qb->toSql();

        $this->assertStringContainsString('(', $sql);
        $this->assertStringContainsString('OR', $sql);
        $this->assertSame([1, 2, 3], $qb->getBindings(), 'outer then nested bindings, in placeholder order');
    }

    public function testWhereHasEmitsCorrelatedExistsSubquery(): void
    {
        $sql = User::whereHas('posts')->toSql();

        $this->assertStringContainsString('exists(', $sql);
        $this->assertStringContainsString('FROM wp_posts', $sql);
        $this->assertStringContainsString('`wp_users`.`id`=`wp_posts`.`user_id`', $sql);
    }

    public function testWhereHasAppliesConstraintInsideSubquery(): void
    {
        $sql = User::whereHas('posts', function ($q) {
            $q->where('published', 1);
        })->toSql();

        $this->assertStringContainsString('exists(', $sql);
        $this->assertStringContainsString('published', $sql);
    }

    public function testSelectRawBindingsPrecedeWhereBindings(): void
    {
        $qb = User::query()->selectRaw('%s as label', ['L'])->where('id', 5);

        $qb->toSql();

        $this->assertSame(['L', 5], $qb->getBindings(), 'selectRaw bindings compile before where bindings');
    }

    // --- when() --------------------------------------------------------------

    public function testWhenTrueAppliesCallback(): void
    {
        $qb = User::query()->when(true, function ($q) {
            $q->where('active', 1);
        });

        $sql = $qb->toSql();

        $this->assertStringContainsString('active', $sql);
        $this->assertSame([1], $qb->getBindings());
    }

    public function testWhenFalseRunsDefaultBranch(): void
    {
        $qb = User::query()->when(
            false,
            function ($q) {
                $q->where('active', 1);
            },
            function ($q) {
                $q->where('active', 0);
            }
        );

        $qb->toSql();

        $this->assertSame([0], $qb->getBindings(), 'default branch applied when value is falsey');
    }

    // --- Constrained eager loading (relation callable $query) ----------------

    public function testWithCallableConstrainsTheEagerLoadQuery(): void
    {
        $builder = User::with('posts', function ($q) {
            $q->where('status', 'published');
        });
        $relation = $builder->getModel()->getRelations()['posts'];

        $this->assertStringContainsString('status', $relation->toSql());
        $this->assertSame(['published'], $relation->getBindings());
    }

    // --- Update path ---------------------------------------------------------

    public function testBulkUpdateExecutesUpdateStatement(): void
    {
        User::where('id', 1)->update(['status' => 'archived']);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringStartsWith('UPDATE wp_users', $sql);
        $this->assertMatchesRegularExpression('/SET\s+status\s*=/i', $sql);
        $this->assertStringContainsString('WHERE', $sql);
    }

    // --- LIKE operator (case-insensitive) ------------------------------------

    public function testLikeOperatorBindsValue(): void
    {
        $qb = User::where('name', 'like', '%ada%');

        $sql = $qb->toSql();

        $this->assertMatchesRegularExpression('/like\s+%s/i', $sql);
        $this->assertSame(['%ada%'], $qb->getBindings(), 'LIKE value must be bound, not concatenated');
    }
}
