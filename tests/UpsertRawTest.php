<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\TimestampedRow;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * upsertRaw() applies developer-authored column expressions on duplicate key.
 *
 * @internal
 *
 * @coversNothing
 */
final class UpsertRawTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /**
     * A column-referencing expression compiles as `col = <expr>`, not `VALUES(col)`.
     */
    public function testUpsertRawEmitsColumnExpressionOnDuplicate(): void
    {
        User::query()->upsertRaw(['email' => 'x@y.com', 'hits' => 1], ['hits' => 'hits + 1']);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE `hits` = hits + 1', $sql);
        $this->assertStringNotContainsString('VALUES(`hits`)', $sql);
    }

    /**
     * A parameterized expression binds its value after the insert values, in order.
     */
    public function testUpsertRawBindsParameterizedExpressionValues(): void
    {
        User::query()->upsertRaw(['email' => 'x@y.com', 'hits' => 1], ['hits' => ['hits + %d', [5]]]);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString("VALUES   ('x@y.com', 1)", $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE `hits` = hits + 5', $sql);
    }

    /**
     * With timestamps enabled, the insert seeds created_at/updated_at, but the
     * duplicate-key set stays exactly the caller's expressions — updated_at is
     * never silently bumped.
     */
    public function testUpsertRawDoesNotAutoBumpUpdatedAtOnDuplicate(): void
    {
        TimestampedRow::query()->upsertRaw(['email' => 'x@y.com', 'hits' => 1], ['hits' => 'hits + 1']);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('`created_at`', $sql);
        $this->assertStringContainsString('`updated_at`', $sql);
        [, $updateClause] = explode('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('`hits` = hits + 1', $updateClause);
        $this->assertStringNotContainsString('updated_at', $updateClause);
    }

    /**
     * A hostile expression is rejected by the RawTemplate hardening.
     */
    public function testUpsertRawRejectsHostileExpression(): void
    {
        $this->expectException(RuntimeException::class);

        User::query()->upsertRaw(['email' => 'x@y.com'], ['hits' => 'hits -- drop']);
    }

    /**
     * At least one update expression is required.
     */
    public function testUpsertRawRequiresAtLeastOneExpression(): void
    {
        $this->expectException(RuntimeException::class);

        User::query()->upsertRaw(['email' => 'x@y.com'], []);
    }

    /**
     * Empty values are a no-op that returns false, like upsert().
     */
    public function testUpsertRawReturnsFalseOnEmptyValues(): void
    {
        $this->assertFalse(User::query()->upsertRaw([], ['hits' => 'hits + 1']));
    }

    /**
     * Regression: plain upsert() still emits VALUES(col) for its update set.
     */
    public function testPlainUpsertStillEmitsValuesFunction(): void
    {
        User::query()->upsert(['first_name' => 'Ada', 'email' => 'a@x.com']);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('`first_name` = VALUES(`first_name`)', $sql);
        $this->assertStringContainsString('`email` = VALUES(`email`)', $sql);
    }
}
