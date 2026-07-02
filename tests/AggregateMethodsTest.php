<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Direct avg()/sum() wrappers (siblings of max()/min()) and the injection guard
 * on aggregate()'s function name.
 */
final class AggregateMethodsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testAvgReturnsValue(): void
    {
        $GLOBALS['wpdb']->resolver = function () {
            return [(object) ['AVG' => '42.5']];
        };

        $this->assertSame('42.5', User::query()->avg('score'));
        $this->assertStringContainsString('AVG(', $GLOBALS['wpdb']->last_query);
    }

    public function testSumReturnsValue(): void
    {
        $GLOBALS['wpdb']->resolver = function () {
            return [(object) ['SUM' => '100']];
        };

        $this->assertSame('100', User::query()->sum('score'));
        $this->assertStringContainsString('SUM(', $GLOBALS['wpdb']->last_query);
    }

    public function testAggregateRejectsNonIdentifierFunctionName(): void
    {
        $this->expectException(RuntimeException::class);

        User::query()->aggregate('COUNT(*); DROP TABLE users; --', 'id');
    }

    public function testAggregateAllowsNonAllowlistedAggregate(): void
    {
        $GLOBALS['wpdb']->resolver = function () {
            return [(object) ['GROUP_CONCAT' => 'a,b']];
        };

        $this->assertSame('a,b', User::query()->aggregate('GROUP_CONCAT', 'score'));
    }
}
