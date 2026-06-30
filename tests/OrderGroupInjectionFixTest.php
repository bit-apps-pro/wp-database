<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * E2: orderBy()/groupBy() reject non-identifier columns (injection guard) while
 * leaving every currently-valid identifier byte-identical.
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

    public function testOrderByPlainColumnUnchanged(): void
    {
        $sql = User::query()->orderBy('id', 'DESC')->toSql();

        $this->assertStringContainsString('ORDER BY id ASC', $sql);
    }

    public function testOrderByQualifiedColumnUnchanged(): void
    {
        $sql = User::query()->orderBy('t.col')->toSql();

        $this->assertStringContainsString('ORDER BY t.col ASC', $sql);
    }

    public function testGroupByPlainColumnUnchanged(): void
    {
        $sql = User::query()->groupBy('contact_id')->toSql();

        $this->assertStringContainsString('GROUP BY contact_id', $sql);
    }

    public function testGroupByQualifiedColumnUnchanged(): void
    {
        $sql = User::query()->groupBy('wp_x.module')->toSql();

        $this->assertStringContainsString('GROUP BY wp_x.module', $sql);
    }
}
