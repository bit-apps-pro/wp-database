<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\QueryBuilder;
use BitApps\WPDatabase\Tests\Fixtures\CastModel;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Attribute casting behaviour.
 *
 */
final class AttributeCastTest extends TestCase
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
     * The documented `boolean` cast must convert the value, like `bool`.
     */
    public function testBooleanCastConvertsValue(): void
    {
        $model = new CastModel(['flag' => '1']);

        $this->assertSame(true, $model->flag);
    }

    /**
     * withCast() on the builder must stay chainable (return the QueryBuilder).
     */
    public function testWithCastOnBuilderIsChainable(): void
    {
        $this->assertInstanceOf(QueryBuilder::class, User::query()->withCast(['flag' => 'bool']));
    }
}
