<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\QueryBuilder;
use BitApps\WPDatabase\Tests\Fixtures\AccessorModel;
use BitApps\WPDatabase\Tests\Fixtures\CastModel;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the defects reported by Gemini Code Assist on the PR.
 */
final class GeminiReviewFixesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /** upsert: column names must be ordered to match the (alphabetically) sorted row values. */
    public function testUpsertAlignsColumnsWithValues(): void
    {
        User::query()->upsert(['first_name' => 'Ada', 'email' => 'a@x.com']);

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('(email, first_name)', $sql, 'columns must be sorted to match the ksorted row values');
        $this->assertStringContainsString("('a@x.com', 'Ada')", $sql);
    }

    /** upsert: an explicit `created_at` in the update list must be rewritten to `updated_at`. */
    public function testUpsertSwapsCreatedAtForUpdatedAtOnDuplicate(): void
    {
        User::query()->upsert(
            ['email' => 'a@x.com', 'created_at' => '2020-01-01 00:00:00'],
            ['email', 'created_at']
        );

        $sql = $GLOBALS['wpdb']->last_query;

        $this->assertStringContainsString('updated_at = VALUES(created_at)', $sql);
        $this->assertStringNotContainsString('created_at = VALUES(created_at)', $sql);
    }

    /** The documented `boolean` cast must convert the value, like `bool`. */
    public function testBooleanCastConvertsValue(): void
    {
        $model = new CastModel(['flag' => '1']);

        $this->assertSame(true, $model->flag);
    }

    /** Collection::pluck must resolve dynamic (accessor) attributes on models. */
    public function testPluckResolvesAccessorAttribute(): void
    {
        $collection = new Collection([new AccessorModel(['id' => 1])]);

        $this->assertSame(['L'], $collection->pluck('label')->all());
    }

    /** withCast() on the builder must stay chainable (return the QueryBuilder). */
    public function testWithCastOnBuilderIsChainable(): void
    {
        $this->assertInstanceOf(QueryBuilder::class, User::query()->withCast(['flag' => 'bool']));
    }
}
