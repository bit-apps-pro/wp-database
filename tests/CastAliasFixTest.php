<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\CastAliasModel;
use DateTime;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * B1: documented cast aliases (integer/float/double/json/datetime) map to the
 * existing casters instead of being silent no-ops.
 */
final class CastAliasFixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testIntegerAliasCastsToInt(): void
    {
        $model = new CastAliasModel(['n' => '42']);

        $this->assertSame(42, $model->n);
    }

    public function testFloatAliasCastsToFloat(): void
    {
        $model = new CastAliasModel(['f' => '4.5']);

        $this->assertSame(4.5, $model->f);
    }

    public function testDoubleAliasCastsToFloat(): void
    {
        $model = new CastAliasModel(['d' => '2.5']);

        $this->assertSame(2.5, $model->d);
    }

    public function testJsonAliasDecodesToArray(): void
    {
        $model = new CastAliasModel(['data' => '{"x":1}']);

        $this->assertSame(['x' => 1], $model->data);
    }

    public function testDatetimeAliasCastsToDate(): void
    {
        $model = new CastAliasModel(['at' => '2024-01-02 03:04:05']);

        $this->assertInstanceOf(DateTime::class, $model->at);
    }
}
