<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\ScopedSoftPost;
use BitApps\WPDatabase\Tests\Fixtures\SoftPost;
use BitApps\WPDatabase\Tests\Fixtures\UnscopedSoftPost;
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

    // Default: a $soft_deletes model hides trashed rows without any opt-in flag
    public function testSoftDeletesModelFiltersByDefault(): void
    {
        $sql = SoftPost::query()->toSql();
        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertStringContainsString('IS NULL', $sql);
    }

    // Opt-out: $soft_delete_scope = false restores the unfiltered read
    public function testSoftDeleteScopeFalseOptsOutOfFilter(): void
    {
        $sql = UnscopedSoftPost::query()->toSql();
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

    // refresh() reloads a row by PK with withTrashed(), so a trashed row is still
    // found and exists() stays true (no re-INSERT on the next save()).
    public function testRefreshReloadsTrashedRowWithoutScopeFilter(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['id' => 1, 'deleted_at' => '2026-01-01 00:00:00']];
        };

        $post = new SoftPost(1); // constructor triggers refresh()

        $this->assertTrue($post->exists(), 'refresh() must find the row even when trashed');
        $this->assertStringNotContainsString(
            'deleted_at',
            $GLOBALS['wpdb']->last_query,
            'refresh() must not scope out trashed rows (withTrashed)'
        );
    }
}
