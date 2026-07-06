<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\PrefixedModel;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of SELECT column preparation (aliases), JOIN variants/ON
 * chaining, and aggregate compilation (count/max/min on a clone).
 */
final class SelectJoinAggregateEdgeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    // --- Select aliases ------------------------------------------------------

    public function testDottedColumnAliasKeepsDotUnquotedAliasBackticked(): void
    {
        $this->assertStringContainsString('t.col AS `a`', (new User())->select(['t.col AS a'])->toSql());
    }

    public function testLowercaseAsNormalisesToUppercase(): void
    {
        $this->assertStringContainsString('`wp_users`.`col` AS `a`', (new User())->select(['col as a'])->toSql());
    }

    public function testAlreadyBacktickedAliasIsNotDoubleQuoted(): void
    {
        $this->assertStringContainsString('`wp_users`.`col` AS `a`', (new User())->select(['col AS `a`'])->toSql());
    }

    public function testAliasWithSpacesIsBacktickedWhole(): void
    {
        $this->assertStringContainsString('`wp_users`.`col` AS `the alias`', (new User())->select(['col AS the alias'])->toSql());
    }

    public function testMultipleAsSplitsOnFirst(): void
    {
        $this->assertStringContainsString('`wp_users`.`a` AS `b as c`', (new User())->select(['a as b as c'])->toSql());
    }

    public function testSelectThenSelectRawJoinedByComma(): void
    {
        $this->assertStringContainsString('`wp_users`.`id`, COUNT(*) as c', (new User())->select(['id'])->selectRaw('COUNT(*) as c')->toSql());
    }

    public function testEmptySelectEmitsQualifiedNothing(): void
    {
        $this->assertStringContainsString('SELECT  FROM wp_users', (new User())->toSql());
    }

    public function testPrepareColumnNameStarStaysQualifiedStar(): void
    {
        $this->assertSame('`wp_users`.*', (new User())->prepareColumnName('*'));
    }

    // --- Joins ---------------------------------------------------------------

    public function testRightFullCrossJoinKeywords(): void
    {
        $this->assertStringContainsString('RIGHT JOIN wp_posts', (new User())->rightJoin('posts', 'posts.user_id', '=', 'users.id')->toSql());
        $this->assertStringContainsString('FULL JOIN wp_posts', (new User())->fullJoin('posts', 'posts.user_id', '=', 'users.id')->toSql());
        $this->assertStringContainsString('CROSS JOIN wp_posts', (new User())->crossJoin('posts', 'posts.user_id', '=', 'users.id')->toSql());
    }

    public function testOnAndOrOnAppendToSameJoin(): void
    {
        // Unprefixed model/join table names in ON columns resolve to physical.
        $and = (new User())->join('posts', 'posts.user_id', '=', 'users.id')->on('posts.status', '=', 'users.state')->toSql();
        $this->assertStringContainsString('`wp_posts`.user_id = `wp_users`.id AND `wp_posts`.status = `wp_users`.state', $and);

        $or = (new User())->join('posts', 'posts.user_id', '=', 'users.id')->orOn('posts.status', '=', 'users.state')->toSql();
        $this->assertStringContainsString('`wp_posts`.user_id = `wp_users`.id OR `wp_posts`.status = `wp_users`.state', $or);
    }

    public function testJoinOnCustomPrefixModelQualifiesBaseColumnWithFullPrefix(): void
    {
        $sql = (new PrefixedModel())->join('gadgets', 'gid', '=', 'wid')->toSql();

        $this->assertStringContainsString('INNER JOIN wp_crm_gadgets', $sql);
        $this->assertStringContainsString('`wp_crm_widgets`.`gid` = wp_crm_gadgets.wid', $sql);
    }

    // --- Aggregates / ordering ----------------------------------------------

    public function testCountCompilesCountOfQualifiedPrimaryKey(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['COUNT' => '7']];
        };

        $result = (new User())->count();

        $this->assertSame(7, $result);
        $this->assertStringContainsString('COUNT(`wp_users`.`id`) as COUNT', $GLOBALS['wpdb']->last_query);
    }

    public function testMaxAndMinReturnNullOnEmptyResultSet(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [];
        };

        $this->assertNull((new User())->max('score'));
        $this->assertNull((new User())->min('score'));
    }

    public function testAggregateRunsOnCloneWithoutMutatingSelect(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['MAX' => 9]];
        };

        $query  = (new User())->select(['id', 'name']);
        $before = $query->toSql();
        $query->max('score');

        $this->assertSame($before, $query->toSql());
    }

    public function testAggregateCarriesWhereClause(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['COUNT' => '3']];
        };

        (new User())->where('active', 1)->count();

        $this->assertStringContainsString('WHERE', $GLOBALS['wpdb']->last_query);
        $this->assertStringContainsString('`wp_users`.`active`', $GLOBALS['wpdb']->last_query);
    }

    public function testAscFallsBackToPrimaryKey(): void
    {
        $this->assertStringContainsString('ORDER BY id ASC', (new User())->asc()->toSql());
    }

    public function testSkipWithoutTakeOmitsOffset(): void
    {
        $this->assertStringNotContainsString('OFFSET', (new User())->skip(20)->toSql());
    }
}
