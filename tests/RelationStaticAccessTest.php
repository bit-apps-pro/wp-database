<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\Model;
use BitApps\WPDatabase\QueryBuilder;
use BitApps\WPDatabase\Tests\Fixtures\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Relation eager-loading entry methods must be reachable both statically
 * (`User::with(...)`) and on an instance (`(new User)->with(...)`), and must
 * produce identical SQL either way. Moving them onto QueryBuilder (forwarded
 * from Model via __call/__callStatic) is what makes the static form legal —
 * PHP only routes through __callStatic for methods that are not defined on the
 * class itself.
 */
final class RelationStaticAccessTest extends TestCase
{
    /** Relation entry methods that take a single relation name. */
    public static function singleRelationMethods(): array
    {
        return [
            'with'        => ['with'],
            'withCount'   => ['withCount'],
            'withMin'     => ['withMin'],
            'withMax'     => ['withMax'],
            'withAvg'     => ['withAvg'],
            'withSum'     => ['withSum'],
            'withExists'  => ['withExists'],
            'whereHas'    => ['whereHas'],
            'withWhereHas' => ['withWhereHas'],
        ];
    }

    #[DataProvider('singleRelationMethods')]
    public function testRelationMethodIsCallableStatically(string $method): void
    {
        $builder = User::$method('posts');

        $this->assertInstanceOf(
            QueryBuilder::class,
            $builder,
            "Model::{$method}() must be callable statically and return a QueryBuilder"
        );
    }

    #[DataProvider('singleRelationMethods')]
    public function testRelationMethodIsCallableOnInstance(string $method): void
    {
        $builder = (new User())->{$method}('posts');

        $this->assertInstanceOf(QueryBuilder::class, $builder);
    }

    #[DataProvider('singleRelationMethods')]
    public function testStaticAndInstanceProduceIdenticalSql(string $method): void
    {
        $static   = User::$method('posts')->toSql();
        $instance = (new User())->{$method}('posts')->toSql();

        $this->assertSame($instance, $static);
    }

    public function testWithRegistersRelationOnModelStatically(): void
    {
        $builder = User::with('posts');

        $this->assertArrayHasKey('posts', $builder->getModel()->getRelations());
    }

    public function testWithCountStaticSqlMatchesBaseline(): void
    {
        $expected = 'SELECT `wp_users`.*, (SELECT count(*) FROM wp_posts WHERE  '
            . '`wp_users`.`id`=`wp_posts`.`user_id`) as `posts_count` FROM wp_users';

        $this->assertSame($expected, User::withCount('posts')->toSql());
    }

    public function testWhereHasStaticSqlMatchesBaseline(): void
    {
        $expected = 'SELECT  FROM wp_users WHERE  exists(SELECT `wp_posts`.* FROM '
            . 'wp_posts WHERE  `wp_users`.`id`=`wp_posts`.`user_id`)';

        $this->assertSame($expected, User::whereHas('posts')->toSql());
    }

    public function testWithExistsStaticSqlMatchesBaseline(): void
    {
        $expected = 'SELECT `wp_users`.*, exists(SELECT `wp_posts`.* FROM wp_posts '
            . 'WHERE  `wp_users`.`id`=`wp_posts`.`user_id`) as `posts_exists` FROM wp_users';

        $this->assertSame($expected, User::withExists('posts')->toSql());
    }

    public function testWithIsChainableAfterBuilderMethod(): void
    {
        $builder = User::where('id', '>', 0)->with('posts');

        $this->assertInstanceOf(QueryBuilder::class, $builder);
        $this->assertArrayHasKey('posts', $builder->getModel()->getRelations());
    }

    public function testWithAcceptsArrayOfRelations(): void
    {
        $relations = User::with(['posts'])->getModel()->getRelations();

        $this->assertArrayHasKey('posts', $relations);
    }

    public function testWithSupportsClosureConstraintStatically(): void
    {
        $builder = User::with('posts', function (QueryBuilder $query) {
            $query->where('status', 'published');
        });

        $this->assertArrayHasKey('posts', $builder->getModel()->getRelations());
    }
}
