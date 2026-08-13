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
        ];
    }

    #[DataProvider('operatorProvider')]
    public function testNormalizesFiniteOperatorSet(string $input, string $expected): void
    {
        $this->assertSame($expected, SqlOperator::normalizeBinary($input));
    }

    public static function specializedOperatorProvider(): array
    {
        return [
            'list'           => ['normalizeList', 'in', 'IN'],
            'negative list'  => ['normalizeList', 'not in', 'NOT IN'],
            'unary'          => ['normalizeUnary', 'is null', 'IS NULL'],
            'negative unary' => ['normalizeUnary', 'is not null', 'IS NOT NULL'],
            'range'          => ['normalizeRange', 'between', 'BETWEEN'],
        ];
    }

    #[DataProvider('specializedOperatorProvider')]
    public function testNormalizesOperatorsOnlyWithinTheirShape(string $method, string $input, string $expected): void
    {
        $this->assertSame($expected, SqlOperator::{$method}($input));
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

        SqlOperator::normalizeBinary($operator);
    }

    public static function joinTypeProvider(): array
    {
        return [
            'inner' => ['inner', 'INNER'],
            'left'  => ['left', 'LEFT'],
            'right' => ['right', 'RIGHT'],
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
            'mysql unsupported'   => ['FULL'],
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

    public static function incompatibleOperatorShapeProvider(): array
    {
        return [
            'where unary with value' => [static function (): void {
                User::query()->where('id', 'IS NULL', 1)->get();
            }],
            'where range with scalar' => [static function (): void {
                User::query()->where('id', 'BETWEEN', 1)->get();
            }],
            'having unary with value' => [static function (): void {
                User::query()->having('id', 'IS NOT NULL', 1)->get();
            }],
            'having range with scalar' => [static function (): void {
                User::query()->having('id', 'BETWEEN', 1)->get();
            }],
            'join list with columns' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', 'IN', 'users.id')->get();
            }],
            'join unary with columns' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', 'IS NULL', 'users.id')->get();
            }],
            'on range with columns' => [static function (): void {
                User::query()->join('posts', 'posts.user_id', '=', 'users.id')
                    ->on('posts.score', 'BETWEEN', 'users.score')->get();
            }],
        ];
    }

    #[DataProvider('incompatibleOperatorShapeProvider')]
    public function testBinaryApisRejectIncompatibleOperatorShapesBeforeExecution(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Expected the binary API to reject a non-binary operator.');
        } catch (RuntimeException $exception) {
            $this->assertSame([], $GLOBALS['wpdb']->queries);
        }
    }

    public function testFullJoinIsRejectedBecauseMysqlDoesNotSupportIt(): void
    {
        try {
            User::query()->fullJoin('posts', 'posts.user_id', '=', 'users.id')->get();
            $this->fail('Expected FULL JOIN to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame([], $GLOBALS['wpdb']->queries);
        }
    }

    public function testBaseAliasQualifiesEveryStructuredBareColumnPath(): void
    {
        $sql = User::query()->from('u')
            ->select('id')
            ->where('status', 'active')
            ->groupBy('role')
            ->having('score', '>', 10)
            ->orderBy('created_at')
            ->toSql();

        $this->assertStringContainsString('SELECT `u`.`id`', $sql);
        $this->assertStringContainsString('WHERE  `u`.`status`', $sql);
        $this->assertStringContainsString('GROUP BY `u`.`role`', $sql);
        $this->assertStringContainsString('HAVING  `u`.`score`', $sql);
        $this->assertStringContainsString('ORDER BY `u`.`created_at`', $sql);
        $this->assertStringNotContainsString('`wp_users`.`', $sql);
    }

    public function testBaseAliasQualifiesAggregateColumn(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['MAX' => 20]];
        };

        User::query()->from('u')->max('score');

        $this->assertStringContainsString('MAX(`u`.`score`)', $GLOBALS['wpdb']->last_query);
    }

    public function testUpdateJoinWhereRendersBindingsInPlaceholderOrder(): void
    {
        User::query()->joinWhere('posts', 'posts.status', '=', 'active')
            ->where('users.id', 7)
            ->update(['status' => 'archived']);

        $this->assertStringContainsString("`wp_posts`.`status` = 'active'", $GLOBALS['wpdb']->last_query);
        $this->assertStringContainsString("SET `status` = 'archived'", $GLOBALS['wpdb']->last_query);
        $this->assertStringContainsString('`wp_users`.`id` =  7', $GLOBALS['wpdb']->last_query);
    }

    public function testUpdateOnValueRendersBindingsInPlaceholderOrder(): void
    {
        User::query()->join('posts', 'posts.user_id', '=', 'users.id')
            ->onValue('posts.status', '=', 'active')
            ->where('users.id', 7)
            ->update(['status' => 'archived']);

        $this->assertStringContainsString("`wp_posts`.`status` = 'active'", $GLOBALS['wpdb']->last_query);
        $this->assertStringContainsString("SET `status` = 'archived'", $GLOBALS['wpdb']->last_query);
        $this->assertStringContainsString('`wp_users`.`id` =  7', $GLOBALS['wpdb']->last_query);
    }

    public function testUpdateOnRawRendersBindingsInPlaceholderOrder(): void
    {
        User::query()->join('posts', 'posts.user_id', '=', 'users.id')
            ->onRaw('`wp_posts`.`status` = %s', ['active'])
            ->where('users.id', 7)
            ->update(['status' => 'archived']);

        $this->assertStringContainsString("`wp_posts`.`status` = 'active'", $GLOBALS['wpdb']->last_query);
        $this->assertStringContainsString("SET `status` = 'archived'", $GLOBALS['wpdb']->last_query);
        $this->assertStringContainsString('`wp_users`.`id` =  7', $GLOBALS['wpdb']->last_query);
    }

    public static function listConditionProvider(): array
    {
        return [
            'where IN' => [static function () {
                return User::query()->where('id', 'IN', [1, 2]);
            }, 'WHERE  `wp_users`.`id` IN (%d,%d)'],
            'where NOT IN' => [static function () {
                return User::query()->where('id', 'NOT IN', [1, 2]);
            }, 'WHERE  `wp_users`.`id` NOT IN (%d,%d)'],
            'having IN' => [static function () {
                return User::query()->having('id', 'IN', [1, 2]);
            }, 'HAVING  `wp_users`.`id` IN (%d,%d)'],
            'having NOT IN' => [static function () {
                return User::query()->having('id', 'NOT IN', [1, 2]);
            }, 'HAVING  `wp_users`.`id` NOT IN (%d,%d)'],
        ];
    }

    #[DataProvider('listConditionProvider')]
    public function testWhereAndHavingPreserveNonEmptyListOperators(callable $build, string $expected): void
    {
        $query = $build();

        $this->assertStringContainsString($expected, $query->toSql());
        $this->assertSame([1, 2], $query->getBindings());
    }

    public static function emptyListConditionProvider(): array
    {
        return [
            'where empty IN is false' => [static function () {
                return User::query()->where('id', 'IN', []);
            }, 'WHERE  0 = 1'],
            'where empty NOT IN is true' => [static function () {
                return User::query()->where('id', 'NOT IN', []);
            }, 'WHERE  1 = 1'],
            'having empty IN is false' => [static function () {
                return User::query()->having('id', 'IN', []);
            }, 'HAVING  0 = 1'],
            'having empty NOT IN is true' => [static function () {
                return User::query()->having('id', 'NOT IN', []);
            }, 'HAVING  1 = 1'],
        ];
    }

    #[DataProvider('emptyListConditionProvider')]
    public function testWhereAndHavingCompileEmptyListsToBooleanConstants(callable $build, string $expected): void
    {
        $query = $build();

        $this->assertStringContainsString($expected, $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function testHostileListOperatorIsRejectedBeforeExecution(): void
    {
        try {
            User::query()->where('id', 'IN/**/OR', [1, 2])->get();
            $this->fail('Expected the hostile list operator to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame([], $GLOBALS['wpdb']->queries);
        }
    }

    public static function aliasedWriteProvider(): array
    {
        return [
            'update' => [static function (): void {
                User::query()->from('u')->where('id', 7)->update(['status' => 'archived']);
            }],
            'delete' => [static function (): void {
                User::query()->from('u')->where('id', 7)->delete();
            }],
        ];
    }

    #[DataProvider('aliasedWriteProvider')]
    public function testUnsupportedAliasedWritesAreRejectedBeforeExecution(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Expected an aliased write to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame([], $GLOBALS['wpdb']->queries);
        }
    }
}
