<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Bulk insert() must always return a Collection, even on the re-query-failure
 * fallback path where the post-insert hydration yields nothing.
 */
final class BulkInsertReturnTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testBulkInsertReturnsFallbackCollectionWhenReQueryYieldsNothing(): void
    {
        // Force the re-query-failure fallback: the INSERT succeeds (rows_affected = 2)
        // but the post-insert SELECT re-query hydrates nothing (last_result stays []).
        $GLOBALS['wpdb']->rows_affected = 2;
        $GLOBALS['wpdb']->insert_id     = 10;
        // last_result defaults to [] — get() returns [], triggering the fallback path.

        $result = User::query()->insert([['name' => 'a'], ['name' => 'b']]);

        $this->assertInstanceOf(Collection::class, $result);
    }

    public function testBulkInsertHappyPathReturnsCollection(): void
    {
        // Happy path: INSERT succeeds and re-query hydrates the rows into Models.
        $GLOBALS['wpdb']->rows_affected = 2;
        $GLOBALS['wpdb']->insert_id     = 10;
        $GLOBALS['wpdb']->queueResult([
            (object) ['id' => 10, 'name' => 'a'],
            (object) ['id' => 11, 'name' => 'b'],
        ]);

        $result = User::query()->insert([['name' => 'a'], ['name' => 'b']]);

        $this->assertInstanceOf(Collection::class, $result);
    }
}
