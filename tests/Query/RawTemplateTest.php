<?php

namespace BitApps\WPDatabase\Tests\Query;

use BitApps\WPDatabase\Query\RawTemplate;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RawTemplateTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testCompilesTypedStructureAndExactValuePlaceholders(): void
    {
        $this->assertSame(
            'SELECT `users`.`id` FROM `users` WHERE `users`.`id` = %d ORDER BY `users`.`id` DESC',
            RawTemplate::compile(
                'SELECT {{identifier:column}} FROM {{identifier:table}}'
                    . ' WHERE {{identifier:column}} = %d ORDER BY {{identifier:column}} {{direction:sort}}',
                [7],
                ['column' => 'users.id', 'table' => 'users'],
                ['sort' => 'desc']
            )
        );
    }

    public function invalidTemplateProvider(): array
    {
        return [
            ['SELECT {{identifier:missing}}', [], [], []],
            ['SELECT {{direction:sort}}', [], [], []],
            ['SELECT {{unknown:key}}', [], ['key' => 'id'], []],
            ['SELECT {{identifier:key}}', [], ['key' => 'id; DROP TABLE x'], []],
            ['SELECT id {{direction:sort}}', [], [], ['sort' => 'desc; DROP TABLE x']],
            ["SELECT 'literal'", [], [], []],
            ['SELECT 1; SELECT 2', [], [], []],
            ['SELECT %1$s', ['x'], [], []],
            ['SELECT %i', ['id'], [], []],
            ['SELECT %s', [], [], []],
            ['SELECT 1', ['extra'], [], []],
        ];
    }

    /**
     * @dataProvider invalidTemplateProvider
     */
    public function testRejectsMalformedOrInexactTemplates($template, $bindings, $identifiers, $directions): void
    {
        $this->expectException(RuntimeException::class);
        RawTemplate::compile($template, $bindings, $identifiers, $directions);
    }

    public function testRawPreparedBindsValuesAndLiteralPercentExactlyOnce(): void
    {
        $wpdb            = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        (new User())->newQuery()->rawPrepared('SELECT %d, 100 %% 7', [7]);

        $this->assertSame(1, $wpdb->prepareCalls);
        $this->assertSame(['SELECT 7, 100 % 7'], $wpdb->queries);
    }

    public function testRawPreparedRejectsBeforePrepareOrQuery(): void
    {
        $wpdb            = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $thrown = false;
        try {
            (new User())->newQuery()->rawPrepared('SELECT %s');
        } catch (RuntimeException $exception) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Expected exact placeholder validation to reject the template.');
        $this->assertSame(0, $wpdb->prepareCalls);
        $this->assertSame([], $wpdb->queries);
    }

    public function testRawPreparedRejectsWpdbPrepareFailureBeforeQuery(): void
    {
        $wpdb                 = new FakeWpdb();
        $wpdb->prepareFailure = true;
        $GLOBALS['wpdb']      = $wpdb;

        $thrown = false;
        try {
            (new User())->newQuery()->rawPrepared('SELECT %s', ['value']);
        } catch (RuntimeException $exception) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Expected wpdb preparation failure to be rejected.');
        $this->assertSame(1, $wpdb->prepareCalls);
        $this->assertSame([], $wpdb->queries);
    }

    public function testUnsafeRawAndDeprecatedRawPreserveLegacyExecution(): void
    {
        $wpdb            = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        (new User())->newQuery()->unsafeRaw('SELECT %s', ['unsafe']);
        (new User())->newQuery()->raw('SELECT %s', ['legacy']);

        $this->assertSame(["SELECT 'unsafe'", "SELECT 'legacy'"], $wpdb->queries);
    }
}
