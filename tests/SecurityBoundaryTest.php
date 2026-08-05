<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Query\Identifier;
use BitApps\WPDatabase\Query\JoinType;
use BitApps\WPDatabase\Query\SqlOperator;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecurityBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testQuotesOnlyStrictIdentifiers(): void
    {
        $this->assertSame('`users`.`id`', Identifier::quoteQualified('users.id'));
        $this->assertSame('`users`.*', Identifier::quoteQualified('users.*', true));

        foreach (['', '1id', 'users..id', 'users.*', '`id`', 'id; DROP TABLE x'] as $identifier) {
            $thrown = false;
            try {
                Identifier::quoteQualified($identifier);
            } catch (RuntimeException $exception) {
                $thrown = true;
                $this->assertSame('Invalid SQL identifier.', $exception->getMessage());
            }
            $this->assertTrue($thrown, 'Expected invalid identifier to be rejected: ' . $identifier);
        }
    }

    public function testFiniteOperatorAndJoinTypeSetsRejectPayloads(): void
    {
        $this->assertSame('NOT LIKE', SqlOperator::normalizeBinary('not like'));
        $this->assertSame('IN', SqlOperator::normalizeList('in'));
        $this->assertSame('IS NULL', SqlOperator::normalizeUnary('is null'));
        $this->assertSame('LEFT', JoinType::normalize('left'));

        foreach (['= 1; DROP TABLE x', 'IN', 'IS NULL'] as $operator) {
            $thrown = false;
            try {
                SqlOperator::normalizeBinary($operator);
            } catch (RuntimeException $exception) {
                $thrown = true;
                $this->assertSame('Invalid SQL operator.', $exception->getMessage());
            }
            $this->assertTrue($thrown, 'Expected invalid binary operator to be rejected.');
        }

        $this->expectException(RuntimeException::class);
        JoinType::normalize('LEFT; DROP TABLE x');
    }

    public function hostileStructuredCallProvider(): array
    {
        return [
            [function (): void { (new User())->newQuery()->select('id; DROP TABLE x')->get(); }],
            [function (): void { (new User())->newQuery()->from('u; DROP TABLE x')->select('id')->get(); }],
            [function (): void { (new User())->newQuery()->where('id; DROP TABLE x', 1)->get(); }],
            [function (): void { (new User())->newQuery()->where('id', '= 1; DROP TABLE x', 1)->get(); }],
            [function (): void { (new User())->newQuery()->whereIn('id; DROP TABLE x', [1])->get(); }],
            [function (): void { (new User())->newQuery()->where(function ($query): void { $query->where('id', 1); }, 'OR; DROP TABLE x')->get(); }],
            [function (): void { (new User())->newQuery()->whereBetween('id; DROP TABLE x', 1, 2)->get(); }],
            [function (): void { (new User())->newQuery()->groupBy('id; DROP TABLE x')->get(); }],
            [function (): void { (new User())->newQuery()->having('id', '= 1; DROP TABLE x', 1)->get(); }],
            [function (): void { (new User())->newQuery()->orderBy('id; DROP TABLE x')->get(); }],
            [function (): void { (new User())->newQuery()->join('posts; DROP TABLE x', 'users.id', '=', 'posts.user_id')->get(); }],
            [function (): void { (new User())->newQuery()->join('posts', 'users.id', '= 1; DROP TABLE x', 'posts.user_id')->get(); }],
            [function (): void { (new User())->newQuery()->join('posts', 'users.id', '=', 'posts.user_id', 'LEFT; DROP TABLE x')->get(); }],
            [function (): void { (new User())->newQuery()->fullJoin('posts', 'users.id', '=', 'posts.user_id')->get(); }],
            [function (): void { (new User())->newQuery()->join('posts', 'users.id', '=', 'posts.user_id')->on('users.id', '=', 'posts.user_id', 'AND; DROP TABLE x')->get(); }],
            [function (): void { (new User())->newQuery()->max('id; DROP TABLE x'); }],
            [function (): void { (new User())->newQuery()->take('1; DROP TABLE x')->get(); }],
            [function (): void { (new User())->newQuery()->skip('1; DROP TABLE x')->take(1)->get(); }],
        ];
    }

    /**
     * @dataProvider hostileStructuredCallProvider
     */
    public function testStructuredPayloadsFailBeforeExecution($call): void
    {
        $thrown = false;
        try {
            $call();
        } catch (RuntimeException $exception) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Expected structured payload to be rejected.');
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testValidStructuredClausesRenderQuotedAndBoundSql(): void
    {
        $query = (new User())->newQuery()
            ->from('u')
            ->select(['u.id', 'p.title AS post_title'])
            ->join('posts AS p', 'u.id', '=', 'p.user_id')
            ->where('u.id', '>=', 7)
            ->whereBetween('p.score', 10, 20)
            ->groupBy(['u.id', 'p.title'])
            ->having('u.id', '>', 1)
            ->orderBy('p.title')
            ->desc()
            ->take(10)
            ->skip(5);

        $query->get();
        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('SELECT `u`.`id`,`p`.`title` AS `post_title` FROM `wp_users` AS `u`', $sql);
        $this->assertStringContainsString('INNER JOIN `wp_posts` AS `p` ON `u`.`id` = `p`.`user_id`', $sql);
        $this->assertStringContainsString('WHERE `u`.`id` >= 7 AND `p`.`score` BETWEEN 10 AND 20', $sql);
        $this->assertStringContainsString('GROUP BY `u`.`id`,`p`.`title` HAVING `u`.`id` > 1', $sql);
        $this->assertStringContainsString('ORDER BY `p`.`title` DESC LIMIT 10 OFFSET 5', $sql);
    }

    public function testEmptyListOperatorsCompileToBooleanConstants(): void
    {
        (new User())->newQuery()->where('id', 'IN', [])->get();
        $this->assertStringContainsString('WHERE 0 = 1', $GLOBALS['wpdb']->last_query);

        (new User())->newQuery()->where('id', 'NOT IN', [])->get();
        $this->assertStringContainsString('WHERE 1 = 1', $GLOBALS['wpdb']->last_query);
    }

    public function testUpdateJoinValuesBindBeforeSetValues(): void
    {
        $query = (new User())->newQuery()
            ->join('posts AS p', 'users.id', '=', 'p.user_id')
            ->onValue('p.kind', '=', 'featured');
        $query->getModel()->setExists(true);
        $sql = $query->update(['name' => 'Ada'])->prepare();

        $this->assertStringContainsString("`p`.`kind` = 'featured'", $sql);
        $this->assertStringContainsString("SET `name` = 'Ada'", $sql);
    }

    public function testJoinWhereUsesABoundRightHandValue(): void
    {
        (new User())->newQuery()
            ->joinWhere('posts', 'posts.status', '=', 'published')
            ->get();

        $this->assertStringContainsString(
            "INNER JOIN `wp_posts` ON `wp_posts`.`status` = 'published'",
            $GLOBALS['wpdb']->last_query
        );
    }

    public function hostileWriteProvider(): array
    {
        return [
            [function (): void { (new User())->newQuery()->insert(['name; DROP TABLE x' => 'Ada']); }],
            [function (): void { (new User())->newQuery()->update(['name; DROP TABLE x' => 'Ada']); }],
            [function (): void { (new User())->newQuery()->insert([['name' => 'Ada'], ['email; DROP TABLE x' => 'x']]); }],
        ];
    }

    /**
     * @dataProvider hostileWriteProvider
     */
    public function testWriteKeysFailBeforeExecution($call): void
    {
        $thrown = false;
        try {
            $call();
        } catch (RuntimeException $exception) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Expected hostile write key to be rejected.');
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testRelationKeysFailAtDefinitionBoundary(): void
    {
        $this->expectException(RuntimeException::class);
        (new User())->newHasMany(User::class, 'id; DROP TABLE x', 'id');
    }

    public function testStructuredWriteColumnsAreQuoted(): void
    {
        $user = new User(['name' => 'Ada']);
        $user->setExists(false);
        $user->save();

        $this->assertStringContainsString('(`name`)', $GLOBALS['wpdb']->last_query);
    }

    public function testArrayValuedFirstFieldRemainsASingleInsertRow(): void
    {
        $GLOBALS['wpdb']->insert_id = 41;

        $result = (new User())->newQuery()->insert([
            'email' => ['primary' => 'ada@example.com'],
            'name'  => 'Ada',
        ]);

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame(
            'INSERT INTO `wp_users` (`email`, `name`) VALUES (\'{"primary":"ada@example.com"}\', \'Ada\')',
            $GLOBALS['wpdb']->last_query
        );
    }

    public function testPositionalArrayOfRowsRemainsABulkInsert(): void
    {
        $GLOBALS['wpdb']->insert_id     = 51;
        $GLOBALS['wpdb']->rows_affected = 2;

        $result = (new User())->newQuery()->insert([
            ['name' => 'Ada'],
            ['name' => 'Grace'],
        ]);

        $this->assertSame([51, 52], $result);
        $this->assertStringContainsString('INSERT INTO `wp_users` (`name`) VALUES', $GLOBALS['wpdb']->queries[0]);
        $this->assertStringContainsString('(\'Ada\'), (\'Grace\')', $GLOBALS['wpdb']->queries[0]);
    }

    public function maliciousInsertShapeProvider(): array
    {
        return [
            'single array-valued row' => [[
                'email'              => ['primary' => 'ada@example.com'],
                'name; DROP TABLE x' => 'Ada',
            ]],
            'bulk rows' => [[
                ['name' => 'Ada'],
                ['email; DROP TABLE x' => 'x'],
            ]],
        ];
    }

    /**
     * @dataProvider maliciousInsertShapeProvider
     */
    public function testInsertShapesRejectMaliciousKeysBeforeExecution($attributes): void
    {
        $caught = null;

        try {
            (new User())->newQuery()->insert($attributes);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }
}
