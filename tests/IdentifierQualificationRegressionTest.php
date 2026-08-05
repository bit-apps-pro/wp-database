<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversNothing]
final class IdentifierQualificationRegressionTest extends TestCase
{
    public function testBareColumnUsesPhysicalModelTable(): void
    {
        $this->assertSame(
            'SELECT `wp_users`.`id` FROM wp_users',
            (new User())->select('id')->toSql()
        );
    }

    public function testLogicalModelQualifierMapsToPhysicalTable(): void
    {
        $this->assertSame(
            'SELECT `wp_users`.`id` FROM wp_users',
            (new User())->select('users.id')->toSql()
        );
    }

    public function testPhysicalModelQualifierIsRecognizedAndQuoted(): void
    {
        $this->assertSame(
            'SELECT `wp_users`.`id` FROM wp_users',
            (new User())->select('wp_users.id')->toSql()
        );
    }

    public function testJoinAliasQualifierIsPreservedAndQuoted(): void
    {
        $this->assertSame(
            'SELECT `p`.`id` FROM wp_users INNER JOIN wp_posts as p'
                . ' ON  `wp_users`.`user_id` = `p`.`id`',
            (new User())->join('posts as p', 'user_id', '=', 'id')->select('p.id')->toSql()
        );
    }

    public function testLogicalJoinedTableMapsWhenSelectPrecedesJoin(): void
    {
        $this->assertSame(
            'SELECT `wp_posts`.`title` FROM wp_users INNER JOIN wp_posts'
                . ' ON  `wp_users`.`user_id` = `wp_posts`.`id`',
            (new User())->select('posts.title')->join('posts', 'user_id', '=', 'id')->toSql()
        );
    }

    public function testQualifiedWildcardResolvesOnlyAtSelectBoundary(): void
    {
        $this->assertSame(
            'SELECT `wp_users`.* FROM wp_users',
            (new User())->select('users.*')->toSql()
        );
    }

    public function testExplicitColumnAliasValidatesAndQuotesBothSides(): void
    {
        $this->assertSame(
            'SELECT `wp_users`.`id` AS `user_id` FROM wp_users',
            (new User())->select('id AS user_id')->toSql()
        );
    }

    public function testFromAliasQualifierIsRecognizedAndQuoted(): void
    {
        $this->assertSame(
            'SELECT `u`.`id` FROM wp_users u',
            (new User())->from('u')->select('u.id')->toSql()
        );
    }

    public function testUnknownQualifierFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);

        (new User())->select('unknown.id')->toSql();
    }

    public function testSchemaQualifiedIdentifierFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);

        (new User())->select('catalog.users.id')->toSql();
    }

    public function testImplicitColumnAliasIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        (new User())->select('id user_id')->toSql();
    }

    public function testPreBacktickedColumnIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        (new User())->select('`id`')->toSql();
    }
}
