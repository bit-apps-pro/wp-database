<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Hosts may install WordPress with a table prefix that starts with a digit
 * (`5c_`, `9x_`, ...). Those tables are legal because every identifier segment
 * is backtick quoted, so compilation must not fail closed on them.
 */
final class DigitLeadingTablePrefixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb']         = new FakeWpdb();
        $GLOBALS['wpdb']->prefix = '5c_';
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testSelectQualifiesColumnsWithDigitLeadingPrefix(): void
    {
        $sql = User::query()->select('id')->toSql();

        $this->assertStringContainsString('`5c_users`.`id`', $sql);
        $this->assertStringContainsString('FROM `5c_users`', $sql);
    }

    public function testJoinAcceptsDigitLeadingPrefixedTable(): void
    {
        $sql = User::query()
            ->join('bit_pi_flows', 'bit_pi_flows.user_id', '=', 'users.id')
            ->toSql();

        $this->assertStringContainsString('JOIN `5c_bit_pi_flows`', $sql);
        $this->assertStringContainsString('`5c_bit_pi_flows`.`user_id` = `5c_users`.`id`', $sql);
    }
}
