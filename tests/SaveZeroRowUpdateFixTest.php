<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * A successful UPDATE that changes 0 rows (an idempotent re-save where no value
 * differs) is success, not failure: exec() returns rows-affected (0) and false
 * only on a real DB error/cancel. So save() must return the Model — otherwise
 * `update(...)->save()` fatals on `false->save()` and `if (!$model->save())`
 * falsely reports an error. A genuine error must still return false.
 */
final class SaveZeroRowUpdateFixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    private function existingUser(): User
    {
        $GLOBALS['wpdb']->resolver = static function () {
            return [(object) ['id' => 1, 'name' => 'Ada']];
        };

        return User::query()->where('id', 1)->first();
    }

    public function testZeroRowUpdateReturnsModelNotFalse(): void
    {
        $user       = $this->existingUser();
        $user->name = 'Grace'; // dirty -> a real UPDATE is built

        $GLOBALS['wpdb']->rows_affected = 0; // matched the row but changed nothing
        $GLOBALS['wpdb']->last_error    = '';

        $result = $user->save();

        $this->assertSame($user, $result, 'a 0-row (no-op) UPDATE is success, must return the Model');
    }

    public function testChainedUpdateSaveDoesNotFatalOnZeroRowUpdate(): void
    {
        $user = $this->existingUser();

        $GLOBALS['wpdb']->rows_affected = 0;

        // update() delegates to save() on an existing model; the trailing save()
        // must land on a Model, not false.
        $result = $user->update(['name' => 'Grace'])->save();

        $this->assertSame($user, $result);
    }

    public function testRealErrorStillReturnsFalse(): void
    {
        $user       = $this->existingUser();
        $user->name = 'Grace';

        $GLOBALS['wpdb']->rows_affected = 0;
        $GLOBALS['wpdb']->last_error    = 'ER_SOMETHING'; // exec() -> false

        $this->assertFalse($user->save(), 'a real DB error must still return false');
    }
}
