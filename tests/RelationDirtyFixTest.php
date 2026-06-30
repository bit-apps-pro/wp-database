<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * C1: lazily reading a relation on a loaded model must not add the relation to
 * the dirty/update write set (which would try to UPDATE a non-existent column),
 * while a real array column value is still written and JSON-encoded.
 */
final class RelationDirtyFixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testLazyRelationAccessIsNotPersistedOnSave(): void
    {
        $GLOBALS['wpdb']->resolver = static function ($sql) {
            if (strpos($sql, 'wp_posts') !== false) {
                return [(object) ['id' => 5, 'user_id' => 1, 'title' => 'p']];
            }

            return [(object) ['id' => 1, 'name' => 'Ada']];
        };

        $user  = User::query()->where('id', 1)->first();
        $posts = $user->posts;

        $this->assertInstanceOf(Collection::class, $posts);
        $this->assertArrayNotHasKey('posts', $user->getDirtyAttributes());

        $user->name = 'Grace';

        $GLOBALS['wpdb']->queries    = [];
        $GLOBALS['wpdb']->last_query = '';

        $user->save();

        $sql = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString('UPDATE wp_users', $sql);
        $this->assertStringContainsString('name =', $sql);
        $this->assertStringNotContainsString('posts', $sql);
    }

    public function testRealArrayColumnIsStillWritten(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['id' => 1, 'name' => 'Ada']];
        };

        $user       = User::query()->where('id', 1)->first();
        $user->tags = ['a', 'b'];

        $GLOBALS['wpdb']->queries    = [];
        $GLOBALS['wpdb']->last_query = '';

        $user->save();

        $sql = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString('tags =', $sql);
        $this->assertStringContainsString('["a","b"]', $sql);
    }
}
