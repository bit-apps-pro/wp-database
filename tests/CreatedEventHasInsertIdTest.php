<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\SavedEventUser;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * The `created` handler must see the auto-increment id on the model, matching
 * Eloquent (which sets the key before firing `created`). Regression guard: the
 * id was previously set only after exec() returned, so `created` saw an empty PK.
 */
final class CreatedEventHasInsertIdTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        SavedEventUser::resetCounters();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        SavedEventUser::resetCounters();
    }

    public function testCreatedHandlerReceivesInsertId(): void
    {
        $GLOBALS['wpdb']->insert_id     = 42;
        $GLOBALS['wpdb']->rows_affected = 1;

        $user       = new SavedEventUser();
        $user->name = 'Ada';
        $user->save();

        $this->assertSame(42, SavedEventUser::$createdSeenId, 'created must see the inserted id');
        $this->assertSame(42, SavedEventUser::$savedSeenId, 'saved must see the inserted id');
    }
}
