<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\QueryBuilder;
use BitApps\WPDatabase\Tests\Fixtures\User;
use PHPUnit\Framework\TestCase;

/**
 * `Model::query()` is the canonical, IDE-navigable entry to the builder: a real
 * static method (Ctrl+Click resolves to it) returning a fresh QueryBuilder, so
 * every subsequent chained call is a real method on a known type.
 */
final class BuilderEntryTest extends TestCase
{
    public function testQueryReturnsFreshQueryBuilder(): void
    {
        $this->assertInstanceOf(QueryBuilder::class, User::query());
    }

    public function testQueryReturnsIndependentInstances(): void
    {
        $this->assertNotSame(User::query(), User::query());
    }

    public function testQueryIsChainable(): void
    {
        $sql = User::query()->where('id', 1)->toSql();

        $this->assertStringContainsString('FROM wp_users', $sql);
        $this->assertStringContainsString('WHERE', $sql);
    }

    public function testQueryChainsRelationMethods(): void
    {
        $this->assertSame(
            User::withCount('posts')->toSql(),
            User::query()->withCount('posts')->toSql()
        );
    }
}
