<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\Tests\Fixtures\CreatingUser;
use BitApps\WPDatabase\Tests\Fixtures\TimestampedRow;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Write schemas are structural SQL input: every column is validated before a
 * query is emitted, and all valid columns are rendered as identifiers only at
 * SQL compilation time.
 */
final class WriteIdentifierSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testInsertRejectsHostileFirstRowKeyWithoutQuery(): void
    {
        $this->assertWriteIsRejected(static function (): void {
            User::query()->insert(['name) VALUES (1); --' => 'Ada']);
        });
    }

    public function testBulkInsertRejectsHostileLaterRowKeyWithoutQuery(): void
    {
        $this->assertWriteIsRejected(static function (): void {
            User::query()->insert([
                ['name' => 'Ada'],
                ['email) VALUES (1); --' => 'ada@example.test'],
            ]);
        });
    }

    public function testUpdateRejectsHostileKeyWithoutQuery(): void
    {
        $this->assertWriteIsRejected(static function (): void {
            User::query()->update(['name = NULL; --' => 'Ada']);
        });
    }

    public function testSaveRejectsHostileModelAttributeWithoutQuery(): void
    {
        $this->assertWriteIsRejected(static function (): void {
            $user = new User(['name = NULL; --' => 'Ada']);
            $user->setExists(false);
            $user->save();
        });
    }

    public function testUpsertRejectsHostileLaterRowKeyWithoutQuery(): void
    {
        $this->assertWriteIsRejected(static function (): void {
            User::query()->upsert([
                ['name' => 'Ada'],
                ['email) VALUES (1); --' => 'ada@example.test'],
            ]);
        });
    }

    public function testUpsertRejectsHostileExplicitUpdateColumnWithoutQuery(): void
    {
        $this->assertWriteIsRejected(static function (): void {
            User::query()->upsert(['email' => 'ada@example.test'], ['email = VALUES(email); --']);
        });
    }

    public function testUpsertEmptyRowsReturnsFalseWithoutQuery(): void
    {
        $this->assertFalse(User::query()->upsert([]));
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testUpdateEmptyAttributesReturnsFalseWithoutQuery(): void
    {
        $this->assertFalse(User::query()->update([]));
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testSaveNewModelWithoutAttributesReturnsFalseWithoutQuery(): void
    {
        $user = new User();
        $user->setExists(false);

        $this->assertFalse($user->save());
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testUpdateWithOnlyRejectedFillableAttributesReturnsFalseWithoutQuery(): void
    {
        $query = CreatingUser::query();
        $query->getModel()->setExists(false);

        $this->assertFalse($query->update(['email' => 'ada@example.test']));
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testTimestampedBulkInsertWithOnlyEmptyRowsReturnsEmptyCollectionWithoutQuery(): void
    {
        $result = TimestampedRow::query()->insert([[], []]);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testBulkInsertNormalizesFirstSeenUnionAndRaggedValues(): void
    {
        $GLOBALS['wpdb']->rows_affected = 0;

        User::query()->insert([
            ['name' => 'Ada', 'meta' => ['active' => true]],
            ['custom_field_12' => null, 'name' => 'Grace'],
            ['meta' => ['active' => false], 'email' => 'grace@example.test'],
        ]);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('(`name`, `meta`, `custom_field_12`, `email`)', $sql);
        $this->assertStringContainsString("('Ada', '{\"active\":true}', NULL, NULL)", $sql);
        $this->assertStringContainsString("('Grace', NULL, NULL, NULL)", $sql);
        $this->assertStringContainsString("(NULL, '{\"active\":false}', NULL, 'grace@example.test')", $sql);
    }

    public function testUpsertNormalizesRowsAndRendersIdentifiers(): void
    {
        User::query()->upsert([
            ['email' => 'ada@example.test', 'meta' => ['active' => true]],
            ['custom_field_12' => null, 'email' => 'grace@example.test'],
        ]);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('(`email`, `meta`, `custom_field_12`)', $sql);
        $this->assertStringContainsString("('ada@example.test', '{\"active\":true}', NULL)", $sql);
        $this->assertStringContainsString("('grace@example.test', NULL, NULL)", $sql);
        $this->assertStringContainsString('`email` = VALUES(`email`)', $sql);
        $this->assertStringContainsString('`meta` = VALUES(`meta`)', $sql);
    }

    public function testTimestampedUpsertAddsManagedColumnsOnlyOnce(): void
    {
        TimestampedRow::query()->upsert([
            ['email' => 'ada@example.test', 'created_at' => '2020-01-01 00:00:00'],
            ['email' => 'grace@example.test', 'updated_at' => '2020-01-02 00:00:00'],
        ]);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertSame(1, substr_count($this->columnList($sql), '`created_at`'));
        $this->assertSame(1, substr_count($this->columnList($sql), '`updated_at`'));
        $this->assertStringContainsString("('ada@example.test', '2020-01-01 00:00:00', NULL)", $sql);
        $this->assertStringContainsString("('grace@example.test', NULL, '2020-01-02 00:00:00')", $sql);
    }

    public function testSingleWritesRenderQuotedIdentifiers(): void
    {
        $GLOBALS['wpdb']->insert_id = 1;
        User::query()->insert(['custom_field_12' => null]);
        $this->assertStringContainsString('(`custom_field_12`) VALUES (NULL)', $GLOBALS['wpdb']->last_query);

        User::query()->update(['custom_field_12' => null]);
        $this->assertStringContainsString('SET `custom_field_12` = NULL', $GLOBALS['wpdb']->last_query);

        $user = new User(['custom_field_12' => null]);
        $user->setExists(false);
        $user->save();
        $this->assertStringContainsString('(`custom_field_12`) VALUES (NULL)', $GLOBALS['wpdb']->last_query);
    }

    private function assertWriteIsRejected(callable $write): void
    {
        try {
            $write();
            $this->fail('Expected hostile write schema to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Invalid SQL identifier.', $exception->getMessage());
        }

        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    private function columnList(string $sql): string
    {
        preg_match('/INSERT INTO [^(]+(\([^)]*\))/', $sql, $matches);

        return $matches[1] ?? '';
    }
}
