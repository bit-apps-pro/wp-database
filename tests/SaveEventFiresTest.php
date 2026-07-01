<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\SavedEventUser;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * The `saved` event must fire on every successful write — including the two
 * paths the save() fixes now treat as success (a 0-row UPDATE and a manual-PK
 * INSERT) — and must NOT fire when the write fails.
 */
final class SaveEventFiresTest extends TestCase
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

    public function testSavedFiresOnZeroRowUpdate(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['id' => 1, 'name' => 'Ada']];
        };

        $user       = SavedEventUser::query()->where('id', 1)->first(); // existing
        $user->name = 'Grace';

        $GLOBALS['wpdb']->rows_affected = 0; // no-op UPDATE
        SavedEventUser::resetCounters();     // ignore anything fired during load

        $user->save();

        $this->assertSame(1, SavedEventUser::$savedCount, 'saved must fire on a 0-row UPDATE');
    }

    public function testUpdatedFiresOnZeroRowUpdate(): void
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['id' => 1, 'name' => 'Ada']];
        };

        $user       = SavedEventUser::query()->where('id', 1)->first();
        $user->name = 'Grace';

        $GLOBALS['wpdb']->rows_affected = 0;
        SavedEventUser::resetCounters();

        $user->save();

        $this->assertSame(1, SavedEventUser::$updatedCount, 'updated must fire even on a 0-row UPDATE');
    }

    public function testCreatedFiresOnInsert(): void
    {
        $GLOBALS['wpdb']->insert_id     = 5;
        $GLOBALS['wpdb']->rows_affected = 1;

        $user       = new SavedEventUser();
        $user->name = 'Ada';
        $user->save();

        $this->assertSame(1, SavedEventUser::$createdCount, 'created must fire on a successful INSERT');
    }

    public function testSavedFiresOnManualPkInsert(): void
    {
        $GLOBALS['wpdb']->insert_id     = 0; // no auto-increment id
        $GLOBALS['wpdb']->rows_affected = 1; // insert succeeded
        $GLOBALS['wpdb']->last_error    = '';

        $user       = new SavedEventUser();
        $user->name = 'Ada';
        $user->save();

        $this->assertSame(1, SavedEventUser::$savedCount, 'saved must fire on a successful manual-PK INSERT');
    }

    public function testSavedDoesNotFireOnFailedWrite(): void
    {
        $GLOBALS['wpdb']->last_error = 'ER_DUP_ENTRY'; // exec() -> false

        $user       = new SavedEventUser();
        $user->name = 'Ada';
        $result     = $user->save();

        $this->assertFalse($result);
        $this->assertSame(0, SavedEventUser::$savedCount, 'saved must NOT fire when the write fails');
    }
}
