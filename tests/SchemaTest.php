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
 *
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

    public function testDropPrimaryDirectCallEmitsFullSql(): void
    {
        Schema::dropPrimary('orders');

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('ALTER TABLE `orders`', $sql);
        $this->assertStringContainsString('DROP PRIMARY KEY', $sql);
    }

    public function testDropTimestampsDirectCallEmitsFullSql(): void
    {
        Schema::dropTimestamps('orders');

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('ALTER TABLE `orders`', $sql);
        $this->assertStringContainsString('DROP COLUMN created_at, DROP COLUMN updated_at', $sql);
    }

    public function testDropIndexDirectCallSingleNameEmitsFullSql(): void
    {
        Schema::dropIndex('orders', 'email_INDEX');

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('ALTER TABLE `orders`', $sql);
        $this->assertStringContainsString('DROP INDEX `email_INDEX`', $sql);
    }

    public function testDropIndexDirectCallArrayNamesEmitsFullSql(): void
    {
        Schema::dropIndex('orders', ['a', 'b']);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('DROP INDEX `a`', $sql);
        $this->assertStringContainsString('DROP INDEX `b`', $sql);
    }

    public function testDropUniqueDirectCallEmitsDropIndexSql(): void
    {
        Schema::dropUnique('orders', 'email_UNIQUE');

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('ALTER TABLE `orders`', $sql);
        $this->assertStringContainsString('DROP INDEX `email_UNIQUE`', $sql);
    }

    public function testDropForeignDirectCallEmitsFullSql(): void
    {
        Schema::dropForeign('orders', 'fk_user');

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('ALTER TABLE `orders`', $sql);
        $this->assertStringContainsString('DROP FOREIGN KEY `fk_user`', $sql);
    }

    public function testDropPrimaryEditModeStillWorks(): void
    {
        Schema::edit('orders', function ($table) {
            $table->dropPrimary();
        });

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('DROP PRIMARY KEY', $sql);
    }
}
