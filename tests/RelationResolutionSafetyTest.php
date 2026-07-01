<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\RelationSentinel;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
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
}
