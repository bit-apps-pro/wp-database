<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\Tests\Fixtures\Post;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof that eager loading still resolves and attaches related data
 * after the relation entry methods moved to QueryBuilder. The fake wpdb returns
 * posts for any query against the posts table and users otherwise.
 */
final class EagerLoadIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb']->resolver = function ($sql) {
            if (strpos($sql, 'wp_posts') !== false) {
                return [
                    (object) ['id' => 10, 'user_id' => 1],
                    (object) ['id' => 11, 'user_id' => 1],
                    (object) ['id' => 12, 'user_id' => 2],
                ];
            }

            return [
                (object) ['id' => 1],
                (object) ['id' => 2],
            ];
        };
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testStaticWithEagerLoadsAndGroupsRelatedRows(): void
    {
        $users = User::with('posts')->get();

        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);

        $first  = $users[0];
        $second = $users[1];

        $this->assertCount(2, $first->posts, 'user 1 should have 2 posts');
        $this->assertCount(1, $second->posts, 'user 2 should have 1 post');
        $this->assertInstanceOf(Post::class, $first->posts[0]);
    }
}
