<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\PrefixedModel;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * A join (and pivot) table must carry the same full prefix the model applies to
 * its own table. For custom-$prefix models (e.g. bit-crm) that is wp_<prefix>...,
 * not just <prefix>... — regression guard for the join double-prefix fix.
 */
final class TablePrefixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testCustomPrefixModelTableCarriesWpPrefix(): void
    {
        $this->assertSame('wp_crm_widgets', (new PrefixedModel())->getTable());
    }

    public function testJoinOnCustomPrefixModelKeepsWpPrefix(): void
    {
        $sql = (new PrefixedModel())
            ->join('gadgets', 'gadgets.widget_id', '=', 'widgets.id')
            ->toSql();

        $this->assertStringContainsString('JOIN wp_crm_gadgets', $sql);
    }
}
