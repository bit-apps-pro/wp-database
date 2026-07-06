<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of relation querying edges: constraint closures on
 * eager/whereHas/withCount, multiple existence filters, relation aliases, and
 * the parent filter propagating into the eager key subquery.
 */
final class RelationEagerEdgeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    private function eagerResolver(): callable
    {
        return static function ($sql) {
            if (strpos($sql, 'wp_posts') !== false) {
                return [
                    (object) ['id' => 10, 'user_id' => 1],
                    (object) ['id' => 11, 'user_id' => 1],
                    (object) ['id' => 12, 'user_id' => 2],
                ];
            }

            return [(object) ['id' => 1], (object) ['id' => 2]];
        };
    }

    public function testWhereHasClosureConstrainsExistsSubquery(): void
    {
        $sql = User::whereHas('posts', static function ($q) {
            $q->where('status', 'published');
        })->toSql();

        $this->assertStringContainsString('exists(', $sql);
        $this->assertStringContainsString('`wp_posts`.`status`', $sql);
        $this->assertStringContainsString('`wp_users`.`id`=`wp_posts`.`user_id`', $sql);
    }

    public function testMultipleWhereHasAreAnded(): void
    {
        $sql = User::whereHas('posts')->whereHas('posts')->toSql();

        $this->assertSame(2, substr_count($sql, 'exists('));
    }

    public function testWithCountClosureNarrowsCountSubquery(): void
    {
        $sql = User::withCount(['posts' => static function ($q) {
            $q->where('status', 'published');
        }])->toSql();

        $this->assertStringContainsString('count(*)', $sql);
        $this->assertStringContainsString('`wp_posts`.`status`', $sql);
        $this->assertStringContainsString('as `posts_count`', $sql);
    }

    public function testWithCountAliasNamesTheColumn(): void
    {
        $this->assertStringContainsString('as `c`', User::withCount('posts as c')->toSql());
    }

    public function testSelectThenWithCountDoesNotDuplicateBaseSelect(): void
    {
        $sql = User::select(['id'])->withCount('posts')->toSql();

        $this->assertStringContainsString('`wp_users`.`id`, (SELECT count(*)', $sql);
        $this->assertStringNotContainsString('`wp_users`.*', $sql);
    }

    public function testEagerClosureConstraintFiltersTheRelationQuery(): void
    {
        $GLOBALS['wpdb']->resolver = $this->eagerResolver();

        User::with(['posts' => static function ($q) {
            $q->where('status', 'published');
        }])->get();

        $postsSql = $GLOBALS['wpdb']->queries[1];

        $this->assertStringContainsString('`wp_posts`.`status`', $postsSql);
        $this->assertStringContainsString('IN ( SELECT * FROM (', $postsSql);
    }

    public function testParentWherePropagatesIntoEagerSubquery(): void
    {
        $GLOBALS['wpdb']->resolver = $this->eagerResolver();

        User::where('status', 'active')->with('posts')->get();

        $postsSql = $GLOBALS['wpdb']->queries[1];

        $this->assertStringContainsString('`wp_users`.`status`', $postsSql);
        $this->assertStringContainsString('AS subquery', $postsSql);
    }

    public function testEagerRelationAliasAttachesUnderAlias(): void
    {
        $GLOBALS['wpdb']->resolver = $this->eagerResolver();

        $users = User::with('posts as recent')->get();

        // eager-attached relations are plain arrays (lazy access returns a Collection).
        $this->assertCount(2, $users[0]->recent);
        $this->assertSame(10, $users[0]->recent[0]->id);
    }

    public function testEagerLoadOnFirstAttachesRelation(): void
    {
        $GLOBALS['wpdb']->resolver = static function ($sql) {
            if (strpos($sql, 'wp_posts') !== false) {
                return [(object) ['id' => 10, 'user_id' => 1]];
            }

            return [(object) ['id' => 1]];
        };

        $user = User::with('posts')->where('id', 1)->first();

        $this->assertCount(1, $user->posts);
        $this->assertSame(10, $user->posts[0]->id);
    }
}
