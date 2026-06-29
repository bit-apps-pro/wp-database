<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\QueryBuilder;
use BitApps\WPDatabase\Tests\Fixtures\User;
use PHPUnit\Framework\TestCase;

/**
 * Locks the exact SELECT SQL produced by the extracted
 * {@see \BitApps\WPDatabase\Query\Grammar}. Every assertion drives the public
 * QueryBuilder API and checks the compiled string from toSql(); no $wpdb access
 * is involved, so these are pure compilation guards.
 */
final class GrammarTest extends TestCase
{
    public function testSelectAllCompilesToQualifiedStar(): void
    {
        $this->assertSame(
            'SELECT `wp_users`.* FROM wp_users',
            User::select('*')->toSql()
        );
    }

    public function testSelectSpecificColumns(): void
    {
        $this->assertSame(
            'SELECT `wp_users`.`id`,`wp_users`.`user_login` FROM wp_users',
            (new User())->select('id', 'user_login')->toSql()
        );
    }

    public function testWhereEquals(): void
    {
        $this->assertSame(
            'SELECT  FROM wp_users WHERE  `wp_users`.`id` =  %d',
            (new User())->where('id', 5)->toSql()
        );
    }

    public function testWhereWithOperator(): void
    {
        $this->assertSame(
            'SELECT  FROM wp_users WHERE  `wp_users`.`id` > %d',
            (new User())->where('id', '>', 0)->toSql()
        );
    }

    public function testOrWhere(): void
    {
        $this->assertSame(
            'SELECT  FROM wp_users WHERE  `wp_users`.`a` =  %d OR `wp_users`.`b` =  %d',
            (new User())->where('a', 1)->orWhere('b', 2)->toSql()
        );
    }

    public function testNestedClosureWhereProducesParenthesizedGroup(): void
    {
        $sql = User::where('a', 1)->where(function ($q) {
            $q->where('b', 2)->orWhere('c', 3);
        })->toSql();

        $this->assertSame(
            'SELECT  FROM wp_users WHERE  `wp_users`.`a` =  %d AND '
            . '( `wp_users`.`b` =  %d OR `wp_users`.`c` =  %d)',
            $sql
        );

        $this->assertStringContainsString('( `wp_users`.`b` =  %d OR `wp_users`.`c` =  %d)', $sql);
    }

    public function testJoin(): void
    {
        $this->assertSame(
            'SELECT  FROM wp_users INNER JOIN wp_wp_posts ON  `wp_users`.`user_id` = id',
            (new User())->join('posts', 'user_id', '=', 'id')->toSql()
        );
    }

    public function testGroupBy(): void
    {
        $this->assertSame(
            'SELECT  FROM wp_users GROUP BY status',
            (new User())->groupBy('status')->toSql()
        );
    }

    public function testHaving(): void
    {
        $this->assertSame(
            'SELECT  FROM wp_users GROUP BY status HAVING  `wp_users`.`id` > %d',
            (new User())->groupBy('status')->having('id', '>', 1)->toSql()
        );
    }

    public function testOrderByDesc(): void
    {
        $this->assertSame(
            'SELECT  FROM wp_users ORDER BY id DESC',
            (new User())->orderBy('id')->desc()->toSql()
        );
    }

    public function testLimitAndOffset(): void
    {
        $this->assertSame(
            'SELECT  FROM wp_users LIMIT 10 OFFSET 20',
            (new User())->take(10)->skip(20)->toSql()
        );
    }

    public function testLimitWithoutOffsetOmitsOffsetClause(): void
    {
        $this->assertSame(
            'SELECT  FROM wp_users LIMIT 5',
            (new User())->take(5)->toSql()
        );
    }

    public function testCompileSelectReturnsString(): void
    {
        $query = (new User())->select('*');

        $this->assertIsString($query->grammar()->compileSelect($query));
        $this->assertInstanceOf(QueryBuilder::class, $query);
    }
}
