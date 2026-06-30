<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * The eager-load key subquery feeds an IN (...) value list and must project
 * only the key column. A parent GROUP BY / HAVING (HAVING references stripped
 * raw aliases) must not leak into it (C4).
 */
final class EagerKeySubqueryScopeFixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb']           = new FakeWpdb();
        $GLOBALS['wpdb']->resolver = static function ($sql) {
            if (strpos($sql, 'wp_posts') !== false) {
                return [(object) ['id' => 10, 'user_id' => 1]];
            }

            return [(object) ['id' => 1]];
        };
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testEagerKeySubqueryDropsParentGroupByAndHaving(): void
    {
        User::groupBy('status')->having('cnt', '>', 1)->with('posts')->get();

        $postsSql = $GLOBALS['wpdb']->queries[1];

        $this->assertStringNotContainsString('GROUP BY', $postsSql);
        $this->assertStringNotContainsString('HAVING', $postsSql);
        $this->assertStringNotContainsString('cnt', $postsSql);
    }
}
