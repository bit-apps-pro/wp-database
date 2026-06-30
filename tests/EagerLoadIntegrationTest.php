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

    /**
     * A parent with NO related rows must not trigger a fresh lazy query when the
     * relation is accessed (the eager load already resolved it to empty) — the
     * N+1 the eager load exists to prevent.
     */
    public function testEmptyEagerRelationDoesNotReQueryOnAccess(): void
    {
        $GLOBALS['wpdb']->resolver = static function ($sql) {
            if (strpos($sql, 'wp_posts') !== false) {
                return [(object) ['id' => 10, 'user_id' => 1]];
            }

            return [(object) ['id' => 1], (object) ['id' => 2]];
        };

        $users = User::with('posts')->get();
        $second = null;
        foreach ($users as $u) {
            if ((int) $u->id === 2) {
                $second = $u;
            }
        }

        $GLOBALS['wpdb']->queries = [];
        $posts = $second->posts; // user 2 has no posts

        $this->assertCount(0, $posts, 'empty eager relation resolves to empty');
        $this->assertCount(0, $GLOBALS['wpdb']->queries, 'no re-query (N+1) on accessing an empty eager relation');
    }

    /**
     * The parent's selectRaw must NOT leak into the eager key subquery — that
     * subquery feeds an IN (...) and must return exactly one column (the localKey),
     * else MySQL raises "Operand should contain 1 column(s)".
     */
    public function testEagerLoadKeySubqueryIgnoresParentSelectRaw(): void
    {
        User::select(['id'])->selectRaw('CONCAT("X", id) as cx')->with('posts')->get();

        $postsSql = $GLOBALS['wpdb']->queries[1];

        $this->assertStringNotContainsString('CONCAT', $postsSql);
        $this->assertStringContainsString(
            'IN ( SELECT * FROM (SELECT `wp_users`.`id` FROM wp_users',
            $postsSql
        );
    }

    /**
     * ORDER BY is meaningless in a value-list IN ( SELECT key ... ) and may
     * reference a stripped selectRaw alias — so it is dropped from the key
     * subquery when no LIMIT pins the set.
     */
    public function testEagerLoadKeySubqueryDropsParentOrderBy(): void
    {
        User::select(['id'])->selectRaw('CONCAT("X", id) as cx')->orderBy('cx', 'DESC')->with('posts')->get();

        $postsSql = $GLOBALS['wpdb']->queries[1];

        $this->assertStringNotContainsString('ORDER BY', $postsSql);
        $this->assertStringNotContainsString('cx', $postsSql);
    }

    /**
     * When a LIMIT pins which parent rows the set comes from, ORDER BY is kept
     * so the limited set stays deterministic.
     */
    public function testEagerLoadKeySubqueryKeepsOrderByWhenLimited(): void
    {
        User::orderBy('id', 'DESC')->take(5)->with('posts')->get();

        $postsSql = $GLOBALS['wpdb']->queries[1];

        $this->assertStringContainsString('ORDER BY', $postsSql);
        $this->assertStringContainsString('LIMIT 5', $postsSql);
    }
}
