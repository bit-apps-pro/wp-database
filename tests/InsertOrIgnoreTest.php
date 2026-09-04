<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * insertOrIgnore() emits INSERT IGNORE while reusing insert()'s value/binding path.
 *
 * @internal
 */
#[CoversNothing]
final class InsertOrIgnoreTest extends TestCase
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
     * A single row emits INSERT IGNORE with columns and bound values aligned.
     */
    public function testInsertOrIgnoreEmitsIgnoreModifierForSingleRow(): void
    {
        User::query()->insertOrIgnore(['first_name' => 'Ada', 'email' => 'a@x.com']);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('INSERT IGNORE INTO wp_users', $sql);
        $this->assertStringContainsString('(`first_name`, `email`)', $sql);
        $this->assertStringContainsString("('Ada', 'a@x.com')", $sql);
    }

    /**
     * A list of rows emits a single multi-tuple INSERT IGNORE.
     */
    public function testInsertOrIgnoreSupportsMultipleRows(): void
    {
        User::query()->insertOrIgnore([['name' => 'a'], ['name' => 'b']]);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('INSERT IGNORE INTO wp_users', $sql);
        $this->assertStringContainsString("('a'), ('b')", $sql);
    }

    /**
     * Array/object values are JSON-encoded, matching insert()/upsert().
     */
    public function testInsertOrIgnoreEncodesArrayValuesAsJson(): void
    {
        User::query()->insertOrIgnore(['email' => 'a@x.com', 'meta' => ['x' => 1]]);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('{"x":1}', $sql);
        $this->assertStringNotContainsString('Array', $sql);
    }

    /**
     * Empty input is a no-op that returns false, like insert().
     */
    public function testInsertOrIgnoreReturnsFalseOnEmptyInput(): void
    {
        $this->assertFalse(User::query()->insertOrIgnore([]));
    }

    /**
     * Regression: the plain bulk insert path still omits the IGNORE modifier.
     */
    public function testPlainBulkInsertStillOmitsIgnoreModifier(): void
    {
        User::query()->insert([['name' => 'a'], ['name' => 'b']]);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('INSERT INTO wp_users', $sql);
        $this->assertStringNotContainsString('INSERT IGNORE', $sql);
    }
}
