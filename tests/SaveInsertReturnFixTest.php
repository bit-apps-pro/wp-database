<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * save()'s insert branch decides success from exec(), not from lastInsertId():
 * a successful INSERT into a table with a manual/composite key returns insert_id
 * 0 yet still succeeded, so it must return the Model (not false). The
 * auto-increment id must still be assigned to the primary key when present, and
 * a genuine insert error must still return false.
 */
final class SaveInsertReturnFixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testAutoIncrementInsertSetsPrimaryKeyFromInsertId(): void
    {
        $GLOBALS['wpdb']->insert_id     = 7;
        $GLOBALS['wpdb']->rows_affected = 1;

        $user       = new User();
        $user->name = 'Ada';
        $result     = $user->save();

        $this->assertSame($user, $result);
        $this->assertEquals(7, $user->getAttribute('id'), 'auto-increment id must be set from lastInsertId()');
    }

    public function testManualPkInsertReturnsModelWhenNoAutoIncrementId(): void
    {
        $GLOBALS['wpdb']->insert_id     = 0; // manual/composite key -> no auto id
        $GLOBALS['wpdb']->rows_affected = 1; // insert succeeded
        $GLOBALS['wpdb']->last_error    = '';

        $user       = new User();
        $user->name = 'Ada';
        $result     = $user->save();

        $this->assertSame($user, $result, 'successful insert with no auto-increment id must return the Model');
    }

    public function testInsertErrorStillReturnsFalse(): void
    {
        $GLOBALS['wpdb']->insert_id  = 0;
        $GLOBALS['wpdb']->last_error = 'ER_DUP_ENTRY'; // exec() -> false

        $user       = new User();
        $user->name = 'Ada';

        $this->assertFalse($user->save(), 'a failed insert must return false');
    }

    public function testFailedInsertWithStaleInsertIdReturnsFalseAndDoesNotSetPk(): void
    {
        $GLOBALS['wpdb']->insert_id  = 99;             // stale id from an earlier insert
        $GLOBALS['wpdb']->last_error = 'ER_DUP_ENTRY'; // this insert failed -> exec() false

        $user       = new User();
        $user->name = 'Ada';
        $result     = $user->save();

        $this->assertFalse($result, 'a failed insert must return false even with a stale insert_id');
        $this->assertNotEquals(99, $user->getAttribute('id'), 'a failed insert must not set the PK from a stale insert_id');
    }
}
