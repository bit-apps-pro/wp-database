<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\QueryBuilder;
use BitApps\WPDatabase\Tests\Fixtures\RelationUser;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RelationResolutionSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function invalidRelationProvider(): array
    {
        return [
            'framework model method' => ['getTable'],
            'scalar-returning method' => ['scalarRelation'],
            'missing method'          => ['missingRelation'],
            'non-relation builder'    => ['plainQuery'],
        ];
    }

    /**
     * @dataProvider invalidRelationProvider
     */
    public function testWithRejectsMethodsThatAreNotRelations($relation): void
    {
        $caught = null;

        try {
            (new RelationUser())->newQuery()->with($relation);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testWithAcceptsGenuineRelation(): void
    {
        $query = (new RelationUser())->newQuery()->with('posts');

        $relations = $query->getModel()->getRelations();
        $this->assertArrayHasKey('posts', $relations);
        $this->assertInstanceOf(QueryBuilder::class, $relations['posts']);
        $this->assertSame('hasMany', $relations['posts']->getModel()->getRelateAs());
    }

    public function testFrameworkMethodIsRejectedBeforeInvocation(): void
    {
        $this->expectException(RuntimeException::class);

        // Invoking setAttribute() without its required arguments would throw an
        // ArgumentCountError, so this proves the framework guard runs first.
        (new RelationUser())->newQuery()->with('setAttribute');
    }
}
