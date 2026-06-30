<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Write-path repairs: insert() routing (A1), empty save() (A5), empty
 * insert/bulk/upsert inputs (A6) and aggregate('*') (A7). Each currently
 * crashes or emits malformed SQL, so the assertions are pure repairs.
 */
final class WriteEdgeFixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    // A1 ---------------------------------------------------------------------

    public function testInsertWithArrayFirstValueInsertsSingleRow(): void
    {
        $GLOBALS['wpdb']->insert_id = 1;

        $result = User::query()->insert(['tags' => ['a'], 'name' => 'x']);

        $this->assertInstanceOf(User::class, $result);

        $sql = $GLOBALS['wpdb']->last_query;
        $this->assertStringStartsWith('INSERT INTO wp_users', $sql);
        $this->assertStringContainsString('["a"]', $sql, 'tags must be JSON-encoded');
        $this->assertStringNotContainsString('),', $sql, 'must be a single VALUES tuple, not bulk');
    }

    // A5 ---------------------------------------------------------------------

    public function testEmptySaveSkipsMalformedUpdateAndReturnsModel(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['id' => 1, 'name' => 'Ada']];
        };

        $user = User::query()->where('id', 1)->first();

        $GLOBALS['wpdb']->queries    = [];
        $GLOBALS['wpdb']->last_query = '';

        $result = $user->save();

        $this->assertSame($user, $result);
        $this->assertSame([], $GLOBALS['wpdb']->queries, 'no UPDATE … SET query may be emitted');
    }

    // A6 ---------------------------------------------------------------------

    public function testInsertEmptyArrayReturnsFalseWithoutQuery(): void
    {
        $result = User::query()->insert([]);

        $this->assertFalse($result);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testBulkInsertEmptyRowsReturnsEmptyCollection(): void
    {
        $result = User::query()->insert([[], []]);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testUpsertEmptyUpdateListDefaultsToAllColumns(): void
    {
        User::query()->upsert(['email' => 'a@x.com'], []);

        $sql = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString('email = VALUES(email)', $sql);
        $this->assertStringNotContainsString('UPDATE ;', $sql);
    }

    // A7 ---------------------------------------------------------------------

    public function testAggregateStarUsesBareStar(): void
    {
        (new User())->aggregate('COUNT', '*');

        $sql = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString('COUNT(*)', $sql);
        $this->assertStringNotContainsString('COUNT(`wp_users`.*)', $sql);
    }

    public function testCountAggregateStillQualifiesPrimaryKey(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['COUNT' => '3']];
        };

        (new User())->count();

        $this->assertStringContainsString('COUNT(`wp_users`.`id`)', $GLOBALS['wpdb']->last_query);
    }
}
