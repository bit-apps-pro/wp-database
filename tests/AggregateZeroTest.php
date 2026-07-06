<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Aggregates must return the actual value the database reports, including the
 * falsy `0` / `'0'`. A genuine zero must not collapse to null.
 */
final class AggregateZeroTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testCountReturnsZeroForEmptyTable(): void
    {
        $GLOBALS['wpdb']->resolver = function () {
            return [(object) ['COUNT' => '0']];
        };

        $this->assertSame(0, User::query()->count());
    }

    public function testMinReturnsZeroValue(): void
    {
        $GLOBALS['wpdb']->resolver = function () {
            return [(object) ['MIN' => '0']];
        };

        $this->assertSame('0', User::query()->min('score'));
    }
}
