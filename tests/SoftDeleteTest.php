<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\SoftPost;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Soft deletes must (a) set `deleted_at = <timestamp>` on the targeted rows and
 * (b) honour the same no-WHERE guard as a hard delete, so a soft delete with no
 * conditions cannot silently rewrite the whole table.
 */
final class SoftDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testSoftDeleteSetsDeletedAtColumnOnMatchedRows(): void
    {
        SoftPost::where('id', 1)->delete();

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('UPDATE wp_soft_posts', $sql);
        $this->assertMatchesRegularExpression('/SET\s+`?\w*\.?`?deleted_at`?\s*=/i', $sql, 'must assign the deleted_at column');
        $this->assertStringContainsString('WHERE', $sql);
    }

    public function testSoftDeleteWithoutWhereIsGuarded(): void
    {
        $this->expectException(RuntimeException::class);

        SoftPost::query()->delete();
    }
}
