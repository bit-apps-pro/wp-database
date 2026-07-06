<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\SoftPost;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Additive soft-delete operations (B2): forceDelete() runs a real DELETE that
 * bypasses the soft-delete rewrite, and restore() nulls deleted_at. Both are
 * only valid on soft-delete models.
 */
final class SoftDeleteRestoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testForceDeleteEmitsRealDeleteBypassingSoftScope(): void
    {
        SoftPost::where('id', 1)->forceDelete();

        $sql = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString('DELETE FROM wp_soft_posts', $sql);
        $this->assertStringNotContainsString('deleted_at', $sql);
    }

    public function testRestoreNullsDeletedAt(): void
    {
        SoftPost::where('id', 1)->restore();

        $sql = $GLOBALS['wpdb']->last_query;
        $this->assertMatchesRegularExpression('/UPDATE\s+wp_soft_posts\s+SET\s+deleted_at\s*=\s*NULL/i', $sql);
    }

    public function testForceDeleteRejectsNonSoftDeleteModel(): void
    {
        $this->expectException(RuntimeException::class);

        User::where('id', 1)->forceDelete();
    }
}
