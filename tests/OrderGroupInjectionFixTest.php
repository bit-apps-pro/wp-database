<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * E2: orderBy()/groupBy() reject non-identifiers and qualifiers outside the
 * query context, while valid columns render as fully qualified identifiers.
 */
final class OrderGroupInjectionFixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testOrderByRejectsInjectionPayload(): void
    {
        $this->expectException(RuntimeException::class);

        User::query()->orderBy('id; DROP TABLE x');
    }

    public function testGroupByRejectsInjectionPayload(): void
    {
        $this->expectException(RuntimeException::class);

        User::query()->groupBy('a); DROP');
    }

    public function testOrderByPlainColumnIsQualified(): void
    {
        $sql = User::query()->orderBy('id', 'DESC')->toSql();

        $this->assertStringContainsString('ORDER BY `wp_users`.`id` ASC', $sql);
    }

    public function testOrderByUnknownQualifiedColumnFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);

        User::query()->orderBy('t.col')->toSql();
    }

    public function testGroupByPlainColumnIsQualified(): void
    {
        $sql = User::query()->groupBy('contact_id')->toSql();

        $this->assertStringContainsString('GROUP BY `wp_users`.`contact_id`', $sql);
    }

    public function testGroupByUnknownQualifiedColumnFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);

        User::query()->groupBy('wp_x.module')->toSql();
    }
}
