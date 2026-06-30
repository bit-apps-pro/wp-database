<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Schema;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Schema::edit() column-modifier behaviour.
 *
 * These tests operate on the literal table name — no prefix is applied because
 * Connection::setPluginPrefix is intentionally not called here.
 */
final class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testChangeEmitsModifyColumn(): void
    {
        Schema::edit('orders', function ($table) {
            $table->varchar('reference', 128)->change();
        });

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('MODIFY COLUMN reference VARCHAR(128)', $sql);
        $this->assertStringNotContainsString('ADD COLUMN', $sql);
    }

    public function testNonChangedEditEmitsAddColumn(): void
    {
        Schema::edit('orders', function ($table) {
            $table->varchar('note', 64);
        });

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('ADD COLUMN note VARCHAR(64)', $sql);
    }
}
