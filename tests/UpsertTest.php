<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\TimestampedRow;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * upsert() column/value alignment and timestamp handling.
 *
 */
final class UpsertTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /**
     * Column names are ordered to match the (alphabetically) sorted row values.
     */
    public function testUpsertAlignsColumnsWithValues(): void
    {
        User::query()->upsert(['first_name' => 'Ada', 'email' => 'a@x.com']);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('(email, first_name)', $sql);
        $this->assertStringContainsString("('a@x.com', 'Ada')", $sql);
    }

    /**
     * With timestamps enabled, upsert inserts both created_at and updated_at, and
     * on duplicate bumps updated_at (VALUES(updated_at)) while preserving created_at
     * (created_at is excluded from the ON DUPLICATE KEY UPDATE set).
     */
    public function testUpsertManagesTimestampsOnDuplicate(): void
    {
        TimestampedRow::query()->upsert(['email' => 'a@x.com', 'name' => 'Ada']);

        $sql = $GLOBALS['wpdb']->last_query;

        // both timestamp columns are inserted
        $this->assertStringContainsString('created_at', $sql);
        $this->assertStringContainsString('updated_at', $sql);
        // updated_at is bumped from its own inserted value, not created_at
        $this->assertStringContainsString('updated_at = VALUES(updated_at)', $sql);
        $this->assertStringNotContainsString('VALUES(created_at)', $sql);
        // created_at is preserved on update (never in the update set)
        $this->assertStringNotContainsString('created_at = VALUES(created_at)', $sql);
    }

    /**
     * With timestamps disabled, upsert applies no timestamp magic — columns map verbatim.
     */
    public function testUpsertWithoutTimestampsHasNoMagic(): void
    {
        User::query()->upsert(
            ['email' => 'a@x.com', 'created_at' => '2020-01-01 00:00:00'],
            ['email', 'created_at']
        );

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('created_at = VALUES(created_at)', $sql);
        $this->assertStringNotContainsString('updated_at', $sql);
    }
}
