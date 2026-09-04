<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * get() returns a Collection for every result shape — one row, no rows, a failed query — and only
 * first()/findOne() collapse to a single Model (or null). Previously a limit of 1 made get() return
 * a bare Model, no rows returned [] and a failed query returned false, forcing every caller to
 * branch on four shapes.
 */
final class GetReturnsCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testGetReturnsCollectionWhenLimitIsOne(): void
    {
        $this->resolveRows([(object) ['id' => 1, 'name' => 'Ada']]);

        $result = User::query()->take(1)->get();

        $this->assertInstanceOf(Collection::class, $result, 'limit 1 must not collapse to a Model');
        $this->assertCount(1, $result);
        $this->assertInstanceOf(User::class, $result[0]);
        $this->assertSame('Ada', $result[0]->name);
    }

    public function testGetReturnsEmptyCollectionWhenNoRowMatches(): void
    {
        $this->resolveRows([]);

        $result = User::query()->where('id', 404)->get();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function testGetReturnsEmptyCollectionWhenQueryFails(): void
    {
        $this->resolveRows([(object) ['id' => 1, 'name' => 'Ada']]);
        $GLOBALS['wpdb']->last_error = 'boom';

        $result = User::query()->get();

        $this->assertInstanceOf(Collection::class, $result, 'a failed query must not return false');
        $this->assertCount(0, $result);
    }

    public function testFindReturnsCollection(): void
    {
        $this->resolveRows([(object) ['id' => 1, 'name' => 'Ada']]);

        $this->assertInstanceOf(Collection::class, User::query()->find(1));
    }

    public function testFirstReturnsTheModel(): void
    {
        $this->resolveRows([(object) ['id' => 1, 'name' => 'Ada']]);

        $user = User::query()->where('id', 1)->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Ada', $user->name);
        $this->assertTrue($user->exists());
    }

    public function testFirstReturnsNullWhenNoRowMatches(): void
    {
        $this->resolveRows([]);

        $this->assertNull(User::query()->where('id', 404)->first());
    }

    public function testFindOneReturnsNullWhenNoRowMatches(): void
    {
        $this->resolveRows([]);

        $this->assertNull(User::query()->findOne(['id' => 404]));
    }

    public function testPaginateCountsASinglePerPageRow(): void
    {
        $this->resolveRows([(object) ['id' => 1, 'name' => 'Ada']]);
        $GLOBALS['wpdb']->rows_affected = 1;

        $page = User::query()->paginate(1, 1);

        $this->assertInstanceOf(Collection::class, $page['data']);
        $this->assertSame(1, $page['current_total'], 'a perPage of 1 used to yield an uncountable Model');
    }

    public function testEveryCollectionRowSavesThroughItsOwnBuilder(): void
    {
        $this->resolveRows([
            (object) ['id' => 1, 'name' => 'Ada'],
            (object) ['id' => 2, 'name' => 'Grace'],
        ]);

        $users = User::query()->get();

        $GLOBALS['wpdb']->queries = [];
        $users[1]->name           = 'Hopper';
        $users[1]->save();

        $sql = $GLOBALS['wpdb']->last_query;
        $this->assertStringStartsWith('UPDATE wp_users', $sql);
        $this->assertStringContainsString("'Hopper'", $sql);
        $this->assertStringContainsString('2', $sql, 'the UPDATE must target the row it was hydrated from');
    }

    /**
     * Queue $rows as the result of every SELECT the test issues.
     *
     * @param array<int,object> $rows
     */
    private function resolveRows(array $rows): void
    {
        $GLOBALS['wpdb']->resolver = static function () use ($rows) {
            return $rows;
        };
    }
}
