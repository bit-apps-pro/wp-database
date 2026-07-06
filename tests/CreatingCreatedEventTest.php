<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\CreatingUser;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

final class CreatingCreatedEventTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb']              = new FakeWpdb();
        CreatingUser::$creatingCalled = false;
        CreatingUser::$createdCalled  = false;
        CreatingUser::$abortCreating  = false;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testCreatingAndCreatedFireOnInsert(): void
    {
        $GLOBALS['wpdb']->insert_id = 1;

        CreatingUser::insert(['name' => 'Ada']);

        $this->assertTrue(CreatingUser::$creatingCalled, 'creating handler should have run');
        $this->assertTrue(CreatingUser::$createdCalled, 'created handler should have run');
    }

    public function testCreatingReturningFalseAbortsInsert(): void
    {
        CreatingUser::$abortCreating = true;

        $result = CreatingUser::insert(['name' => 'Ada']);

        $this->assertSame([], $GLOBALS['wpdb']->queries, 'aborted insert must not execute any query');
        $this->assertFalse($result, 'aborted insert returns false');
    }
}
