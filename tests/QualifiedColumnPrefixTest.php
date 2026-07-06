<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\SoftPost;
use BitApps\WPDatabase\Tests\Fixtures\User;
use PHPUnit\Framework\TestCase;

/**
 * A column qualified by the *unprefixed* model or joined table name
 * (e.g. `users.id` when the physical table is `wp_users`) must resolve to the
 * physical, back-ticked table. Already-physical names, aliases, and unknown
 * tables are left untouched. Pure compilation guards via toSql().
 */
final class QualifiedColumnPrefixTest extends TestCase
{
    public function testWhereResolvesUnprefixedModelTable(): void
    {
        $sql = (new User())->where('users.status', 1)->toSql();
        $this->assertStringContainsString('`wp_users`.status', $sql);
    }

    public function testSelectResolvesModelAndJoinedTable(): void
    {
        $sql = (new User())->join('posts', 'user_id', '=', 'id')
            ->select('users.id', 'posts.title')->toSql();

        $this->assertStringContainsString('`wp_users`.id', $sql);
        $this->assertStringContainsString('`wp_posts`.title', $sql);
    }

    public function testSelectResolvesRegardlessOfJoinOrder(): void
    {
        $sql = (new User())->select('posts.title')
            ->join('posts', 'user_id', '=', 'id')->toSql();

        $this->assertStringContainsString('`wp_posts`.title', $sql);
    }

    public function testJoinOnResolvesBothSides(): void
    {
        $sql = (new User())->join('posts', 'posts.user_id', '=', 'users.id')->toSql();

        $this->assertStringContainsString('`wp_posts`.user_id', $sql);
        $this->assertStringContainsString('`wp_users`.id', $sql);
        $this->assertStringNotContainsString(' users.id', $sql);
    }

    public function testAlreadyPhysicalNameUntouched(): void
    {
        $sql = (new User())->where('wp_users.id', 5)->toSql();

        $this->assertStringContainsString('wp_users.id', $sql);
        $this->assertStringNotContainsString('wp_wp_users', $sql);
        $this->assertStringNotContainsString('`wp_users`.id', $sql);
    }

    public function testAliasShadowingModelNameWins(): void
    {
        $sql = (new User())->from('users')->where('users.id', 5)->toSql();

        $this->assertStringContainsString(' users.id', $sql);
        $this->assertStringNotContainsString('`wp_users`.id', $sql);
    }

    public function testGroupByResolvesJoinedTable(): void
    {
        $sql = (new User())->join('posts', 'user_id', '=', 'id')
            ->groupBy('posts.status')->toSql();

        $this->assertStringContainsString('GROUP BY `wp_posts`.status', $sql);
    }

    public function testOrderByResolvesJoinedTable(): void
    {
        $sql = (new User())->join('posts', 'user_id', '=', 'id')
            ->orderBy('posts.title')->toSql();

        $this->assertStringContainsString('ORDER BY `wp_posts`.title', $sql);
    }

    public function testResolveQualifierIsIdempotent(): void
    {
        $qb    = User::query();
        $once  = $qb->resolveQualifier('users.id');
        $twice = $qb->resolveQualifier($once);

        $this->assertSame('`wp_users`.id', $once);
        $this->assertSame($once, $twice);
    }

    public function testResolveQualifierLeavesUnqualifiedColumnAlone(): void
    {
        $this->assertSame('id', User::query()->resolveQualifier('id'));
    }

    public function testJoinedTableResolvesInsideNestedClosure(): void
    {
        $sql = (new User())->join('posts', 'user_id', '=', 'id')
            ->where(function ($q) {
                $q->where('posts.status', 1);
            })->toSql();

        $this->assertStringContainsString('`wp_posts`.status', $sql);
    }

    public function testJoinedTableResolvesUnderSoftDeleteScope(): void
    {
        $sql = (new SoftPost())->join('users', 'post_id', '=', 'id')
            ->where('users.role', 'admin')->toSql();

        $this->assertStringContainsString('`wp_users`.role', $sql);
    }
}
