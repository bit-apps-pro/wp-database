<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\QueryBuilder;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Guards the API surface that consumers actually use today (e.g. bit-crm):
 * relation *definitions* (hasMany/belongsTo) and the standard query chain.
 * These must keep working after the relation entry methods move to QueryBuilder.
 */
final class RelationDefinitionRegressionTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testRelationDefinitionReturnsQueryBuilder(): void
    {
        $this->assertInstanceOf(QueryBuilder::class, (new User())->posts());
    }

    public function testStaticBuilderChainStillWorks(): void
    {
        $sql = User::where('id', '>', 0)->toSql();

        $this->assertStringContainsString('FROM wp_users', $sql);
        $this->assertStringContainsString('WHERE', $sql);
    }

    public function testGetReturnsCollectionForMultipleRows(): void
    {
        $GLOBALS['wpdb']->queueResult([
            (object) ['id' => 1],
            (object) ['id' => 2],
        ]);

        $result = User::where('id', '>', 0)->get();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
    }
}
