<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Query\JoinType;
use BitApps\WPDatabase\Query\SqlOperator;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Structured query APIs accept only identifiers and finite SQL keyword sets;
 * values and explicitly raw join expressions use separate paths.
 */
final class StructuredClauseSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public static function operatorProvider(): array
    {
        return [
            'equal'              => ['=', '='],
            'not equal bang'     => ['!=', '!='],
            'not equal angle'    => ['<>', '<>'],
            'greater than'       => ['>', '>'],
            'less than'          => ['<', '<'],
            'greater or equal'   => ['>=', '>='],
            'less or equal'      => ['<=', '<='],
            'like'               => ['like', 'LIKE'],
            'not like'           => ['not like', 'NOT LIKE'],
            'in'                 => ['in', 'IN'],
            'not in'             => ['not in', 'NOT IN'],
            'is null'            => ['is null', 'IS NULL'],
            'is not null'        => ['is not null', 'IS NOT NULL'],
            'between'            => ['between', 'BETWEEN'],
        ];
    }

    #[DataProvider('operatorProvider')]
    public function testNormalizesFiniteOperatorSet(string $input, string $expected): void
    {
        $this->assertSame($expected, SqlOperator::normalize($input));
    }

    public static function invalidOperatorProvider(): array
    {
        return [
            'empty'                 => [''],
            'arbitrary keyword'     => ['REGEXP'],
            'comment suffix'        => ['= --'],
            'block comment'         => ['LIKE/**/'],
            'doubled inner space'   => ['NOT  LIKE'],
            'leading whitespace'    => [' LIKE'],
            'trailing whitespace'   => ['LIKE '],
            'newline smuggling'     => [">\n="],
            'boolean payload'       => ['= OR 1=1'],
        ];
    }

    #[DataProvider('invalidOperatorProvider')]
    public function testRejectsOperatorsOutsideFiniteSet(string $operator): void
    {
        $this->expectException(RuntimeException::class);

        SqlOperator::normalize($operator);
    }

    public static function joinTypeProvider(): array
    {
        return [
            'inner' => ['inner', 'INNER'],
            'left'  => ['left', 'LEFT'],
            'right' => ['right', 'RIGHT'],
            'full'  => ['full', 'FULL'],
            'cross' => ['cross', 'CROSS'],
        ];
    }

    #[DataProvider('joinTypeProvider')]
    public function testNormalizesFiniteJoinTypeSet(string $input, string $expected): void
    {
        $this->assertSame($expected, JoinType::normalize($input));
    }

    public static function invalidJoinTypeProvider(): array
    {
        return [
            'empty'               => [''],
            'unsupported'         => ['NATURAL'],
            'outer smuggling'     => ['LEFT OUTER'],
            'comment'             => ['LEFT/**/'],
            'leading whitespace'  => [' LEFT'],
            'trailing whitespace' => ['LEFT '],
            'payload'             => ['LEFT JOIN secrets; --'],
        ];
    }

    #[DataProvider('invalidJoinTypeProvider')]
    public function testRejectsJoinTypesOutsideFiniteSet(string $type): void
    {
        $this->expectException(RuntimeException::class);

        JoinType::normalize($type);
    }

    public static function hostileStructuredPathProvider(): array
    {
        return [
            'from alias' => [static function (): void {
                User::query()->from('u; DROP TABLE x')->get();
            }],
            'select column' => [static function (): void {
                User::query()->select('id; DROP TABLE x')->get();
            }],
            'addSelect column' => [static function (): void {
                User::query()->select('id')->addSelect('a); DROP')->get();
            }],
            'where column' => [static function (): void {
                User::query()->where('id; DROP TABLE x', 1)->get();
            }],
            'where operator' => [static function (): void {
                User::query()->where('id', '= OR 1=1 --', 1)->get();
            }],
            'orWhere column' => [static function (): void {
                User::query()->where('id', 1)->orWhere('a); DROP', 2)->get();
            }],
            'orWhere operator' => [static function (): void {
                User::query()->where('id', 1)->orWhere('name', 'LIKE/**/OR', '%x%')->get();
            }],
            'where boolean connector' => [static function (): void {
                User::query()->where('id', '=', 1, 'OR 1=1 --')->get();
            }],
            'whereIn column' => [static function (): void {
                User::query()->whereIn('id) OR 1=1 --', [1])->get();
            }],
            'whereNull column' => [static function (): void {
                User::query()->whereNull('id; DROP TABLE x')->get();
            }],
            'whereNotNull column' => [static function (): void {
                User::query()->whereNotNull('a); DROP')->get();
            }],
            'whereBetween column' => [static function (): void {
                User::query()->whereBetween('age) OR 1=1 --', 1, 2)->get();
            }],
            'orWhereBetween column' => [static function (): void {
                User::query()->orWhereBetween('age; DROP TABLE x', 1, 2)->get();
            }],
            'having column' => [static function (): void {
                User::query()->having('count) OR 1=1 --', '>', 1)->get();
            }],
            'having operator' => [static function (): void {
                User::query()->having('count', '> OR 1=1', 1)->get();
            }],
            'orHaving column' => [static function (): void {
                User::query()->orHaving('count; DROP TABLE x', '>', 1)->get();
            }],
            'orHaving operator' => [static function (): void {
                User::query()->orHaving('count', 'NOT  LIKE', 'x')->get();
            }],
            'group column reported payload' => [static function (): void {
                User::query()->groupBy('a); DROP')->get();
            }],
            'order column reported payload' => [static function (): void {
                User::query()->orderBy('id; DROP TABLE x')->get();
            }],
            'aggregate column' => [static function (): void {
                User::query()->max('score); DROP TABLE x')->get();
            }],
            'aggregate function' => [static function (): void {
                User::query()->aggregate('COUNT(*); DROP TABLE x; --', 'id');
            }],
            'join table' => [static function (): void {
                User::query()->join('posts; DROP TABLE x', 'posts.user_id', '=', 'users.id')->get();
            }],
            'join alias' => [static function (): void {
                User::query()->join('posts AS p; DROP', 'p.user_id', '=', 'users.id')->get();
            }],
            'join first column' => [static function (): void {
                User::query()->join('posts', 'posts.user_id OR 1=1', '=', 'users.id')->get();
            }],
            'join second column' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', '=', 'users.id OR 1=1')->get();
            }],
            'join operator' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', '= OR 1=1', 'users.id')->get();
            }],
            'join type' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', '=', 'users.id', 'LEFT; DROP')->get();
            }],
            'chained on column' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', '=', 'users.id')
                    ->on('posts.state OR 1=1', '=', 'users.state')->get();
            }],
            'chained on operator' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', '=', 'users.id')
                    ->on('posts.state', '= --', 'users.state')->get();
            }],
            'chained on boolean connector' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', '=', 'users.id')
                    ->on('posts.state', '=', 'users.state', 'OR 1=1 --')->get();
            }],
            'chained orOn column' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', '=', 'users.id')
                    ->orOn('posts.state', '=', 'users.state; DROP')->get();
            }],
            'chained orOn operator' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', '=', 'users.id')
                    ->orOn('posts.state', '!= OR 1=1', 'users.state')->get();
            }],
        ];
    }

    #[DataProvider('hostileStructuredPathProvider')]
    public function testStructuredPathsRejectHostileTokensBeforeExecution(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Expected the structured query path to reject hostile SQL structure.');
        } catch (RuntimeException $exception) {
            $this->assertSame([], $GLOBALS['wpdb']->queries);
        }
    }

    public function testStructuredBetweenClausesRenderIdentifiersAndBindBounds(): void
    {
        $query = User::query()->whereBetween('age', 18, 65)->orWhereBetween('score', 1.5, 9.5);

        $this->assertStringContainsString('`wp_users`.`age` BETWEEN %d AND %d', $query->toSql());
        $this->assertStringContainsString('OR `wp_users`.`score` BETWEEN %f AND %f', $query->toSql());
        $this->assertSame([18, 65, 1.5, 9.5], $query->getBindings());
    }

    public function testJoinValuePathsBindConstants(): void
    {
        $query = User::query()
            ->joinWhere('posts', 'posts.status', '=', 'active')
            ->onValue('posts.score', '>=', 10)
            ->orOnValue('posts.kind', '=', 'featured');

        $sql = $query->toSql();

        $this->assertStringContainsString('`wp_posts`.`status` = %s', $sql);
        $this->assertStringContainsString('AND `wp_posts`.`score` >= %d', $sql);
        $this->assertStringContainsString('OR `wp_posts`.`kind` = %s', $sql);
        $this->assertSame(['active', 10, 'featured'], $query->getBindings());
    }

    public function testExplicitRawJoinPathsDoNotGuessExpressions(): void
    {
        $query = User::query()
            ->join('posts', 'posts.user_id', '=', 'users.id')
            ->onRaw('`wp_posts`.`created_at` < NOW()')
            ->orOnRaw('`wp_posts`.`updated_at` > DATE_SUB(NOW(), INTERVAL %d DAY)', [7]);
        $sql = $query->toSql();

        $this->assertStringContainsString('AND `wp_posts`.`created_at` < NOW()', $sql);
        $this->assertStringContainsString('OR `wp_posts`.`updated_at` > DATE_SUB(NOW(), INTERVAL %d DAY)', $sql);
        $this->assertSame([7], $query->getBindings());
    }

    public static function guessedJoinOperandProvider(): array
    {
        return [
            'numeric constant' => ['5'],
            'quoted constant'  => ["'active'"],
            'function call'    => ['NOW()'],
        ];
    }

    #[DataProvider('guessedJoinOperandProvider')]
    public function testOrdinaryJoinRejectsNonColumnSecondOperands(string $operand): void
    {
        $this->expectException(RuntimeException::class);

        User::query()->join('posts', 'posts.value', '=', $operand);
    }

    public function testStructuredTablesAndAliasesAreQuoted(): void
    {
        $this->assertSame(
            'SELECT `u`.`id` FROM `wp_users` `u` INNER JOIN `wp_posts` AS `p`'
                . ' ON  `p`.`user_id` = `u`.`id`',
            User::query()->from('u')->join('posts AS p', 'p.user_id', '=', 'u.id')->select('u.id')->toSql()
        );
    }
}
