<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `withMin/withMax/withAvg/withSum` are documented to aggregate a related
 * column via the `relation.column` syntax (e.g. `withSum('posts.amount')`).
 * They must parse the relation and the column, emit the aggregate over that
 * column, and alias the result `<relation>_<function>`.
 */
final class RelationAggregateColumnTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function aggregateMethods(): array
    {
        return [
            'withSum' => ['withSum', 'sum'],
            'withMin' => ['withMin', 'min'],
            'withMax' => ['withMax', 'max'],
            'withAvg' => ['withAvg', 'avg'],
        ];
    }

    #[DataProvider('aggregateMethods')]
    public function testAggregateEmitsFunctionOverRelatedColumn(string $method, string $function): void
    {
        $sql = User::$method('posts.amount')->toSql();

        $this->assertStringContainsStringIgnoringCase($function . '(', $sql, "should emit a {$function}() aggregate");
        $this->assertStringContainsString('amount', $sql, 'should aggregate the related `amount` column, not *');
        $this->assertStringContainsString('posts_' . $function, $sql, "should alias the column posts_{$function}");
    }

    #[DataProvider('aggregateMethods')]
    public function testAggregateDoesNotAggregateStar(string $method, string $function): void
    {
        $sql = User::$method('posts.amount')->toSql();

        $this->assertStringNotContainsStringIgnoringCase($function . '(*)', $sql, "{$function}(*) means the column was ignored");
    }
}
