<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JoinValueSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function nullOperatorProvider(): array
    {
        return [
            'equals'         => ['=', 'IS NULL'],
            'not equals'     => ['!=', 'IS NOT NULL'],
            'alternate not'  => ['<>', 'IS NOT NULL'],
        ];
    }

    /**
     * @dataProvider nullOperatorProvider
     */
    public function testJoinWhereNormalizesNullOperators($operator, $expected): void
    {
        (new User())->newQuery()
            ->joinWhere('posts', 'posts.status', $operator, null)
            ->get();

        $this->assertStringContainsString(
            'INNER JOIN `wp_posts` ON `wp_posts`.`status` ' . $expected,
            $GLOBALS['wpdb']->last_query
        );
        $this->assertSame(0, $GLOBALS['wpdb']->prepareCalls);
    }

    /**
     * @dataProvider nullOperatorProvider
     */
    public function testOnValueNormalizesNullOperators($operator, $expected): void
    {
        (new User())->newQuery()
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->onValue('posts.status', $operator, null)
            ->get();

        $this->assertStringContainsString(
            'AND `wp_posts`.`status` ' . $expected,
            $GLOBALS['wpdb']->last_query
        );
        $this->assertSame(0, $GLOBALS['wpdb']->prepareCalls);
    }

    public function invalidValueProvider(): array
    {
        return [
            'array'  => [['unsafe']],
            'object' => [(object) ['unsafe' => true]],
        ];
    }

    /**
     * @dataProvider invalidValueProvider
     */
    public function testJoinWhereRejectsInvalidValuesBeforePreparationOrQuery($value): void
    {
        $caught = null;

        try {
            (new User())->newQuery()->joinWhere('posts', 'posts.status', '=', $value);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame(0, $GLOBALS['wpdb']->prepareCalls);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    /**
     * @dataProvider invalidValueProvider
     */
    public function testOnValueRejectsInvalidValuesBeforePreparationOrQuery($value): void
    {
        $caught = null;

        try {
            (new User())->newQuery()
                ->join('posts', 'users.id', '=', 'posts.user_id')
                ->onValue('posts.status', '=', $value);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame(0, $GLOBALS['wpdb']->prepareCalls);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testJoinValuesRejectResources(): void
    {
        $resource = fopen('php://memory', 'r');
        $caught   = null;

        try {
            (new User())->newQuery()->joinWhere('posts', 'posts.status', '=', $resource);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            fclose($resource);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame(0, $GLOBALS['wpdb']->prepareCalls);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }
}
