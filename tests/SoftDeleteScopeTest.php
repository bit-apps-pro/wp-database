<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\ScopedSoftPost;
use BitApps\WPDatabase\Tests\Fixtures\SoftPost;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

final class SoftDeleteScopeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    // BC GUARD: existing $soft_deletes model must NOT be auto-filtered
    public function testSoftDeletesOnlyModelIsNotFiltered(): void
    {
        $sql = SoftPost::query()->toSql();
        $this->assertStringNotContainsString('deleted_at', $sql);
    }

    // Non-soft-delete model untouched
    public function testNonSoftDeleteModelIsNotFiltered(): void
    {
        $sql = User::query()->toSql();
        $this->assertStringNotContainsString('deleted_at', $sql);
    }

    // Opt-in model: default query filters out trashed rows
    public function testScopedModelDefaultQueryFiltersDeletedAt(): void
    {
        $sql = ScopedSoftPost::query()->toSql();
        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertStringContainsString('IS NULL', $sql);
    }

    // withTrashed() removes the filter
    public function testWithTrashedRemovesDeletedAtFilter(): void
    {
        $sql = ScopedSoftPost::query()->withTrashed()->toSql();
        $this->assertStringNotContainsString('deleted_at', $sql);
    }

    // onlyTrashed() returns only trashed rows
    public function testOnlyTrashedFiltersToTrashedRows(): void
    {
        $sql = ScopedSoftPost::query()->onlyTrashed()->toSql();
        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    // onlyTrashed() works on a non-scoped $soft_deletes model too
    public function testOnlyTrashedWorksWithoutScopeFlag(): void
    {
        $sql = SoftPost::query()->onlyTrashed()->toSql();
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    // Aggregate carries the scope
    public function testAggregateCarriesSoftDeleteScope(): void
    {
        ScopedSoftPost::query()->count();
        $sql = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertStringContainsString('IS NULL', $sql);
    }

    // scope + orWhere: user conditions must be parenthesized to prevent precedence leak
    public function testScopedModelOrWhereGroupsUserConditions(): void
    {
        $sql = ScopedSoftPost::query()
            ->where('status', 'active')
            ->orWhere('status', 'pending')
            ->toSql();

        // scope clause must be outside the OR group
        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertStringContainsString('IS NULL', $sql);
        // user conditions must be parenthesized
        $this->assertMatchesRegularExpression('/\(.*status.*OR.*status.*\)/s', $sql);
        // scope must appear after the closing parenthesis (column may be backtick-quoted)
        $this->assertMatchesRegularExpression('/\).*deleted_at.*IS\s+NULL/s', $sql);
    }

    // onlyTrashed + orWhere: same grouping requirement
    public function testOnlyTrashedOrWhereGroupsUserConditions(): void
    {
        $sql = ScopedSoftPost::query()
            ->where('status', 'active')
            ->orWhere('status', 'pending')
            ->onlyTrashed()
            ->toSql();

        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertStringContainsString('IS NOT NULL', $sql);
        $this->assertMatchesRegularExpression('/\(.*status.*OR.*status.*\)/s', $sql);
        $this->assertMatchesRegularExpression('/\).*deleted_at.*IS\s+NOT\s+NULL/s', $sql);
    }
}
