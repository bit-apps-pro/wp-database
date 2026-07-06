<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * C6: a bulk insert whose rows have differing keys must map each row's value to
 * the header column with the same key (absent columns become NULL), instead of
 * positionally misaligning the values.
 */
final class RaggedBulkInsertFixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testRaggedRowsAlignByColumnKey(): void
    {
        // rows_affected = 0 keeps the INSERT as last_query (no post-insert re-query).
        $GLOBALS['wpdb']->rows_affected = 0;

        User::query()->insert([
            ['a' => 'x', 'b' => 'y'],
            ['b' => 'z', 'c' => 'w'],
        ]);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('(a, b)', $sql);
        $this->assertStringContainsString("('x', 'y')", $sql);
        $this->assertStringContainsString("(NULL, 'z')", $sql);
        $this->assertStringNotContainsString("('z'", $sql);
    }
}
