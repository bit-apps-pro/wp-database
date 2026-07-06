<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Schema;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * B4: Schema DDL repairs — edit-mode unique() prepends ADD, a renameColumn() in
 * an edit() closure is emitted as valid MySQL 8 DDL, and decimal(precision,
 * scale) compiles to DECIMAL(p, s).
 */
final class SchemaDdlFixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testEditUniqueEmitsAddUniqueIndex(): void
    {
        Schema::edit('orders', function ($table) {
            $table->string('email')->unique();
        });

        $this->assertStringContainsString('ADD UNIQUE INDEX', $GLOBALS['wpdb']->last_query);
    }

    public function testEditRenameColumnIsEmitted(): void
    {
        Schema::edit('orders', function ($table) {
            $table->renameColumn('old_name', 'new_name');
        });

        $this->assertStringContainsString(
            'RENAME COLUMN old_name TO new_name',
            $GLOBALS['wpdb']->last_query
        );
    }

    public function testDecimalEmitsPrecisionAndScale(): void
    {
        Schema::create('invoices', function ($table) {
            $table->decimal('amount', 8, 2);
        });

        $this->assertStringContainsString('DECIMAL(8, 2)', $GLOBALS['wpdb']->last_query);
    }
}
