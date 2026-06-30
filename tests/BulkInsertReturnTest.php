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

    /**
     * Array/object values must be JSON-encoded in bulk insert, matching save()/
     * update() — so callers don't have to wp_json_encode() them manually.
     */
    public function testBulkInsertEncodesArrayAndObjectValuesAsJson(): void
    {
        // rows_affected = 0 keeps the INSERT as last_query (no post-insert re-query).
        $GLOBALS['wpdb']->rows_affected = 0;

        User::query()->insert([
            ['name' => 'a', 'meta' => ['x' => 1]],
            ['name' => 'b', 'meta' => (object) ['y' => 2]],
        ]);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('{"x":1}', $sql);
        $this->assertStringContainsString('{"y":2}', $sql);
        $this->assertStringNotContainsString('Array', $sql);
    }
}
