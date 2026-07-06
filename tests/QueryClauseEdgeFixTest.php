<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * WHERE / LIMIT repairs that today emit truncated or syntactically-invalid SQL,
 * fatal on prepare, or allow injection: null with an explicit operator (A2),
 * empty IN sets (A3), nested IN elements (A8), object values (B3) and
 * take()/skip() injection (E1). Asserts at toSql()/binding level (stable),
 * never the substituted last_query.
 */
final class QueryClauseEdgeFixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    // A2 ---------------------------------------------------------------------

    public function testWhereWithExplicitOperatorAndNullEmitsIsNull(): void
    {
        $qb = (new User())->where('id', '=', null);

        $this->assertStringContainsString('`wp_users`.`id` IS NULL', $qb->toSql());
        $qb->toSql();
        $this->assertSame([], $qb->getBindings());
    }

    public function testWhereWithNotEqualNullEmitsIsNotNull(): void
    {
        $this->assertStringContainsString(
            '`wp_users`.`id` IS NOT NULL',
            (new User())->where('id', '!=', null)->toSql()
        );
    }

    // A3 ---------------------------------------------------------------------

    public function testWhereInEmptyArrayEmitsFalseConstant(): void
    {
        $qb = (new User())->whereIn('id', []);

        $sql = $qb->toSql();
        $this->assertStringContainsString('0 = 1', $sql);
        $this->assertStringNotContainsString('IN ()', $sql);
        $this->assertSame([], $qb->getBindings());
    }

    public function testWhereWithEmptyArrayValueEmitsFalseConstant(): void
    {
        $sql = (new User())->where('status', [])->toSql();

        $this->assertStringContainsString('0 = 1', $sql);
        $this->assertStringNotContainsString('IN  ()', $sql);
    }

    // A8 ---------------------------------------------------------------------

    public function testWhereInNestedArrayElementGetsOnePlaceholderEach(): void
    {
        $qb = (new User())->whereIn('id', [[1, 2], 3]);

        $this->assertStringContainsString('IN (%s,%d)', $qb->toSql());
        $this->assertSame(['[1,2]', 3], $qb->getBindings());
    }

    // B3 ---------------------------------------------------------------------

    public function testWhereWithObjectValueBindsJsonString(): void
    {
        $qb = (new User())->where('meta', (object) ['k' => 'v']);

        $this->assertStringContainsString('`wp_users`.`meta` =  %s', $qb->toSql());
        $qb->toSql();
        $this->assertSame(['{"k":"v"}'], $qb->getBindings());
    }

    // E1 ---------------------------------------------------------------------

    public function testTakeCastsArgumentToIntBlockingInjection(): void
    {
        $sql = (new User())->take('5; DROP TABLE wp_users')->toSql();

        $this->assertStringContainsString('LIMIT 5', $sql);
        $this->assertStringNotContainsString('DROP', $sql);
    }

    public function testTakeNumericIsByteIdentical(): void
    {
        $this->assertStringContainsString('LIMIT 10', (new User())->take(10)->toSql());
    }

    public function testSkipCastsArgumentToInt(): void
    {
        $sql = (new User())->take(10)->skip('20; DROP')->toSql();

        $this->assertStringContainsString('OFFSET 20', $sql);
        $this->assertStringNotContainsString('DROP', $sql);
    }
}
