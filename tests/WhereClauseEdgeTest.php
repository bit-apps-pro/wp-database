<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\QueryBuilder;
use BitApps\WPDatabase\Tests\Fixtures\ScopedSoftPost;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of WHERE / IN / NULL / operator / nested-group / binding-order
 * edge cases. Asserts compiled placeholders via toSql() (stable, unlike the
 * substituted last_query) and the binding array, which is the real risk surface.
 */
final class WhereClauseEdgeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /** Bindings assemble during compilation — compile, then read them. */
    private function bindings(QueryBuilder $qb): array
    {
        $qb->toSql();

        return $qb->getBindings();
    }

    public function testWhereInTypesPlaceholdersPerElement(): void
    {
        $qb = (new User())->whereIn('id', [1, 'a', 2.5]);

        $this->assertStringContainsString('`wp_users`.`id` IN (%d,%s,%f)', $qb->toSql());
        $this->assertSame([1, 'a', 2.5], $qb->getBindings());
    }

    public function testWhereInSingleElement(): void
    {
        $qb = (new User())->whereIn('id', [5]);

        $this->assertStringContainsString('`wp_users`.`id` IN (%d)', $qb->toSql());
        $this->assertSame([5], $qb->getBindings());
    }

    public function testWhereInAssocDropsKeys(): void
    {
        $qb = (new User())->whereIn('id', ['x' => 1, 'y' => 2]);

        $this->assertStringContainsString('IN (%d,%d)', $qb->toSql());
        $this->assertSame([1, 2], $qb->getBindings());
    }

    public function testWhereWithArrayValueIsImplicitIn(): void
    {
        $qb = (new User())->where('id', [1, 2, 3]);

        // the 2-arg array path produces "IN  (" (two spaces) — distinct from whereIn's "IN (".
        $this->assertStringContainsString('`wp_users`.`id` IN  (%d,%d,%d)', $qb->toSql());
        $this->assertSame([1, 2, 3], $qb->getBindings());
    }

    public function testWhereNullEmitsIsNull(): void
    {
        $qb = (new User())->where('id', null);

        $this->assertStringContainsString('`wp_users`.`id` IS NULL', $qb->toSql());
        $this->assertSame([], $qb->getBindings());
    }

    public function testWhereNullAndNotNullHelpers(): void
    {
        $this->assertStringContainsString('`wp_users`.`deleted_at` IS NULL', (new User())->whereNull('deleted_at')->toSql());
        $this->assertStringContainsString('`wp_users`.`deleted_at` IS NOT NULL', (new User())->whereNotNull('deleted_at')->toSql());
    }

    public function testFalsyValuesAreNotDropped(): void
    {
        $this->assertSame([0], $this->bindings((new User())->where('id', 0)));
        $this->assertSame([false], $this->bindings((new User())->where('active', false)));
        $this->assertSame(['0'], $this->bindings((new User())->where('id', '0')));
        $this->assertSame([''], $this->bindings((new User())->where('id', '')));
    }

    public function testOperatorVariantsPassThrough(): void
    {
        $this->assertStringContainsString('`wp_users`.`id` != %d', (new User())->where('id', '!=', 5)->toSql());
        $this->assertStringContainsString('`wp_users`.`id` <> %d', (new User())->where('id', '<>', 5)->toSql());
        $this->assertStringContainsString('`wp_users`.`id` >= %d', (new User())->where('id', '>=', 5)->toSql());
    }

    public function testLikeUppercaseAndNotLike(): void
    {
        $this->assertStringContainsString('`wp_users`.`name` LIKE %s', (new User())->where('name', 'LIKE', '%a%')->toSql());
        $this->assertStringContainsString('`wp_users`.`name` NOT LIKE %s', (new User())->where('name', 'NOT LIKE', '%a%')->toSql());
    }

    public function testWhereBetweenAndOrWhereBetween(): void
    {
        $qb = (new User())->whereBetween('age', 18, 65);
        $this->assertStringContainsString('(age BETWEEN %d AND %d)', $qb->toSql());
        $this->assertSame([18, 65], $qb->getBindings());

        $qb2 = (new User())->where('id', 1)->orWhereBetween('age', 18, 65);
        $this->assertStringContainsString('OR  (age BETWEEN %d AND %d)', $qb2->toSql());
        $this->assertSame([1, 18, 65], $qb2->getBindings());
    }

    public function testNestedClosureFirstReordersBindingsToPlaceholderOrder(): void
    {
        $qb = (new User())
            ->where(static function ($q) {
                $q->where('b', 2)->orWhere('c', 3);
            })
            ->where('a', 1);

        $this->assertStringContainsString('( `wp_users`.`b` =  %d OR `wp_users`.`c` =  %d) AND `wp_users`.`a` =  %d', $qb->toSql());
        $this->assertSame([2, 3, 1], $qb->getBindings());
    }

    public function testNestedClosureWithExplicitOrBool(): void
    {
        $qb = (new User())
            ->where('a', 1)
            ->where(static function ($q) {
                $q->where('b', 2);
            }, 'OR');

        $this->assertStringContainsString('`wp_users`.`a` =  %d OR ( `wp_users`.`b` =  %d)', $qb->toSql());
        $this->assertSame([1, 2], $qb->getBindings());
    }

    public function testWhereRawBindingOrder(): void
    {
        $this->assertSame([99, 5], $this->bindings((new User())->whereRaw('x = %d', [99])->where('id', 5)));
        $this->assertSame([5, 99], $this->bindings((new User())->where('id', 5)->whereRaw('x = %d', [99])));
        $this->assertSame([5, 99], $this->bindings((new User())->where('id', 5)->orWhereRaw('x = %d', [99])));
    }

    public function testSelectRawThenWhereThenHavingBindingOrder(): void
    {
        $qb = (new User())
            ->selectRaw('%s as label', ['L'])
            ->where('id', 5)
            ->groupBy('status')
            ->having('cnt', '>', 3);

        $this->assertSame(['L', 5, 3], $this->bindings($qb));
    }

    public function testSoftDeleteScopeKeepsUserBindingOrderThroughParenWrap(): void
    {
        $qb = ScopedSoftPost::query()->where('a', 1)->orWhere('b', 2);

        $sql = $qb->toSql();
        $this->assertStringContainsString('( `wp_scoped_soft_posts`.`a` =  %d OR `wp_scoped_soft_posts`.`b` =  %d)', $sql);
        $this->assertStringContainsString('`deleted_at` IS NULL', $sql);
        $this->assertSame([1, 2], $qb->getBindings());
    }
}
