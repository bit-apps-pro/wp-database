<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\Tests\Fixtures\AccessorModel;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Collection behaviour.
 *
 */
final class CollectionTest extends TestCase
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
     * Collection::pluck must resolve dynamic (accessor) attributes on models.
     */
    public function testPluckResolvesAccessorAttribute(): void
    {
        $collection = new Collection([new AccessorModel(['id' => 1])]);

        $this->assertSame(['L'], $collection->pluck('label')->all());
    }
}
