<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\EventUser;
use BitApps\WPDatabase\Tests\Fixtures\RetrieveUser;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle event contract:
 *  - a `saving` handler returning false aborts the write (no query runs);
 *  - the `retrieved` handler receives an already-hydrated model.
 */
final class ModelEventsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb']          = new FakeWpdb();
        EventUser::$savingCalled  = false;
        RetrieveUser::$seenId     = 'UNSET';
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testSavingReturningFalseAbortsInsert(): void
    {
        $user       = new EventUser();
        $user->name = 'Ada';

        $result = $user->save();

        $this->assertTrue(EventUser::$savingCalled, 'saving handler should have run');
        $this->assertSame([], $GLOBALS['wpdb']->queries, 'aborted save must not execute any query');
        $this->assertFalse($result, 'aborted save returns false');
    }

    public function testRetrievedHandlerReceivesHydratedModel(): void
    {
        $GLOBALS['wpdb']->queueResult([(object) ['id' => 7]]);

        RetrieveUser::first();

        $this->assertSame(7, RetrieveUser::$seenId, 'retrieved must fire after the model is filled');
    }
}
