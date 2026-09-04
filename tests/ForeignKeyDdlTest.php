<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Schema;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * create() pins a storage engine and names its foreign keys, so a declared cascade is actually
 * created (MyISAM silently discards FOREIGN KEY clauses) and dropForeign() has a name to target.
 */
final class ForeignKeyDdlTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testCreateDefaultsToInnoDbSoForeignKeysSurvive(): void
    {
        Schema::create('invoices', function ($table) {
            $table->id();
        });

        $this->assertStringContainsString('ENGINE=InnoDB', $GLOBALS['wpdb']->last_query);
    }

    public function testEngineIsOverridable(): void
    {
        Schema::create('archive_rows', function ($table) {
            $table->id();
            $table->engine('MyISAM');
        });

        $this->assertStringContainsString('ENGINE=MyISAM', $GLOBALS['wpdb']->last_query);
    }

    public function testEngineRejectsNonIdentifierValues(): void
    {
        Schema::create('archive_rows', function ($table) {
            $table->id();
            $table->engine('InnoDB; DROP TABLE wp_users');
        });

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('ENGINE=InnoDB ', $sql);
        $this->assertStringNotContainsString('DROP TABLE', $sql);
    }

    public function testForeignKeyCarriesADeterministicConstraintName(): void
    {
        Schema::create('order_items', function ($table) {
            $table->id();
            $table->bigInt('order_id')->unsigned()->foreign('orders', 'id')->onDelete()->cascade();
        });

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('CONSTRAINT `order_items_order_id_fk`', $sql);
        $this->assertStringContainsString('FOREIGN KEY (order_id) REFERENCES orders (id)', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testConstraintNameStaysWithinTheIdentifierLimit(): void
    {
        $longTable = str_repeat('a', 60);

        Schema::create($longTable, function ($table) {
            $table->id();
            $table->bigInt('order_id')->unsigned()->foreign('orders', 'id')->onDelete()->cascade();
        });

        preg_match('/CONSTRAINT `([^`]+)`/', $GLOBALS['wpdb']->last_query, $matches);

        $this->assertNotEmpty($matches, 'the foreign key should still be named');
        $this->assertLessThanOrEqual(64, \strlen($matches[1]));
    }
}
