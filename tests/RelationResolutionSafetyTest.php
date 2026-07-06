<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Model;
use BitApps\WPDatabase\Tests\Fixtures\RelationLeafModel;
use BitApps\WPDatabase\Tests\Fixtures\RelationSentinel;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * with()/withCount()/whereHas() resolve a relation by calling the model method
 * of that name. A name that does not resolve to a relation query must be
 * rejected with a clear RuntimeException — not silently registered (which then
 * blows up downstream) and not usable as an arbitrary method-call primitive.
 *
 * Genuine relation methods must keep resolving (zero-BC). And
 * getActiveRelationKey() must fail loudly on an unknown relation tag.
 */
final class RelationResolutionSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    private function assertRejectedAsRelation(callable $call): void
    {
        $caught = null;

        try {
            $call();
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            RuntimeException::class,
            $caught,
            'a non-relation method name must be rejected at resolution with a RuntimeException'
        );
    }

    public function testWithRejectsNonRelationMethod(): void
    {
        // must be rejected at resolution (the with() call), not blow up later.
        $this->assertRejectedAsRelation(static function () {
            RelationSentinel::with('destroyTheWorld');
        });
    }

    public function testWithCountRejectsNonRelationMethod(): void
    {
        $this->assertRejectedAsRelation(static function () {
            RelationSentinel::withCount('destroyTheWorld');
        });
    }

    public function testWhereHasRejectsNonRelationMethod(): void
    {
        $this->assertRejectedAsRelation(static function () {
            RelationSentinel::whereHas('destroyTheWorld');
        });
    }

    public function testGenuineRelationStillResolves(): void
    {
        // zero-BC guard: a real relation method must keep resolving, never rejected.
        try {
            RelationSentinel::with('posts')->get();
        } catch (RuntimeException $e) {
            $this->fail('a genuine relation must not be rejected: ' . $e->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    public function testGetActiveRelationKeyFailsLoudlyOnUnknownTag(): void
    {
        $model = new RelationSentinel();
        $model->setRelateAs('not_a_real_tag');

        $threw = false;

        try {
            @$model->getActiveRelationKey();
        } catch (Throwable $e) {
            $threw = true;
        }

        $this->assertTrue(
            $threw,
            'getActiveRelationKey() must fail loudly on an unknown relation tag, not return a silent null'
        );
    }

    // A method that DOES return a QueryBuilder, but one whose model has no active
    // relation key, must still be rejected (the second isRelationQuery() branch).
    public function testWithRejectsMethodReturningNonRelationQueryBuilder(): void
    {
        $this->assertRejectedAsRelation(static function () {
            RelationSentinel::with('plainQuery');
        });
    }

    // A framework Model method (refresh() runs a SELECT) must be rejected — and,
    // per option-α, never invoked.
    public function testFrameworkModelMethodIsRejected(): void
    {
        $this->assertRejectedAsRelation(static function () {
            RelationSentinel::with('refresh');
        });
    }

    // withWhereHas() is the 4th resolution entry point and must reject too.
    public function testWithWhereHasRejectsNonRelationMethod(): void
    {
        $this->assertRejectedAsRelation(static function () {
            RelationSentinel::withWhereHas('destroyTheWorld');
        });
    }

    // A relation declared on an intermediate base class (leaf -> base -> Model)
    // must resolve — its declaring class is the base, not the framework Model.
    public function testRelationOnIntermediateBaseClassIsAllowed(): void
    {
        $threw = false;

        try {
            RelationLeafModel::with('widgets');
        } catch (Throwable $e) {
            $threw = true;
        }

        $this->assertFalse($threw, 'a relation declared on an intermediate base class must resolve');
    }

    // The framework-vs-consumer verdict is memoized per "class::method"; the
    // cache must hold the correct boolean for each and not collide across methods.
    public function testFrameworkVerdictIsMemoizedPerClassMethod(): void
    {
        $cacheProp = new ReflectionProperty(Model::class, 'relationMethodCache');
        if (\PHP_VERSION_ID < 80100) {
            $cacheProp->setAccessible(true); // required on 7.4; a deprecated no-op on 8.1+
        }
        $cacheProp->setValue(null, []);

        try {
            RelationSentinel::with('refresh'); // framework method -> rejected
        } catch (RuntimeException $e) {
            // expected
        }
        RelationSentinel::with('posts'); // consumer relation -> resolves

        $cache  = $cacheProp->getValue();
        $prefix = RelationSentinel::class;

        $this->assertArrayHasKey($prefix . '::refresh', $cache);
        $this->assertTrue($cache[$prefix . '::refresh'], 'refresh memoized as a framework method');
        $this->assertArrayHasKey($prefix . '::posts', $cache);
        $this->assertFalse($cache[$prefix . '::posts'], 'posts memoized as a consumer method');
    }
}
