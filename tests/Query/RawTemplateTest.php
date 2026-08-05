<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Query\RawTemplate;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversNothing]
final class RawTemplateTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testCompilesTypedMarkersAndReusesDuplicateMapValues(): void
    {
        $sql = RawTemplate::compile(
            'SELECT {{identifier:column}} FROM {{identifier:table}}'
                . ' ORDER BY {{identifier:column}} {{direction:sort}}',
            [],
            ['column' => 'users.id', 'table' => 'users'],
            ['sort'   => 'desc']
        );

        $this->assertSame(
            'SELECT `users`.`id` FROM `users` ORDER BY `users`.`id` DESC',
            $sql
        );
    }

    public static function invalidMarkerProvider(): array
    {
        return [
            'missing identifier map entry' => [
                'SELECT {{identifier:column}}',
                [],
                [],
            ],
            'missing direction map entry' => [
                'SELECT id {{direction:sort}}',
                [],
                [],
            ],
            'unused identifier map entry' => [
                'SELECT id',
                ['column' => 'id'],
                [],
            ],
            'unused direction map entry' => [
                'SELECT id',
                [],
                ['sort' => 'ASC'],
            ],
            'unknown marker kind' => [
                'SELECT {{column:name}}',
                ['name' => 'id'],
                [],
            ],
            'invalid marker key' => [
                'SELECT {{identifier:1name}}',
                ['1name' => 'id'],
                [],
            ],
            'unclosed marker' => [
                'SELECT {{identifier:name}',
                ['name' => 'id'],
                [],
            ],
            'stray opening brace' => [
                'SELECT {id',
                [],
                [],
            ],
            'stray closing brace' => [
                'SELECT id}',
                [],
                [],
            ],
        ];
    }

    #[DataProvider('invalidMarkerProvider')]
    public function testRejectsMalformedOrInexactMarkerMaps(
        string $template,
        array $identifiers,
        array $directions
    ): void {
        $this->expectException(RuntimeException::class);

        RawTemplate::compile($template, [], $identifiers, $directions);
    }

    public static function invalidIdentifierProvider(): array
    {
        return [
            'empty'          => [''],
            'leading digit'  => ['1column'],
            'empty segment'  => ['users..id'],
            'wildcard'       => ['users.*'],
            'implicit alias' => ['id alias'],
            'expression'     => ['LOWER(id)'],
        ];
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function testRejectsInvalidIdentifierMarkerValues(string $identifier): void
    {
        $this->expectException(RuntimeException::class);

        RawTemplate::compile(
            'SELECT {{identifier:column}}',
            [],
            ['column' => $identifier]
        );
    }

    public static function invalidDirectionProvider(): array
    {
        return [
            'empty'      => [''],
            'nulls last' => ['DESC NULLS LAST'],
            'comment'    => ['ASC--'],
            'expression' => ['RAND()'],
            'non-string' => [1],
        ];
    }

    #[DataProvider('invalidDirectionProvider')]
    public function testRejectsInvalidDirectionMarkerValues($direction): void
    {
        $this->expectException(RuntimeException::class);

        RawTemplate::compile(
            'SELECT id ORDER BY id {{direction:sort}}',
            [],
            [],
            ['sort' => $direction]
        );
    }

    public static function forbiddenTemplateTokenProvider(): array
    {
        return [
            'single quote'        => ["SELECT 'literal'"],
            'double quote'        => ['SELECT "literal"'],
            'backtick'            => ['SELECT `id`'],
            'hash comment'        => ['SELECT id # comment'],
            'dash comment'        => ['SELECT id -- comment'],
            'block comment open'  => ['SELECT /* comment'],
            'block comment close' => ['SELECT comment */ id'],
        ];
    }

    #[DataProvider('forbiddenTemplateTokenProvider')]
    public function testRejectsEveryForbiddenTemplateToken(string $template): void
    {
        $this->expectException(RuntimeException::class);

        RawTemplate::compile($template);
    }

    public function testAcceptsOnlySupportedUnnumberedValuePlaceholdersAndEscapedPercent(): void
    {
        $this->assertSame(
            'SELECT %s, %d, %f, %F, %%',
            RawTemplate::compile('SELECT %s, %d, %f, %F, %%', ['x', 1, 1.5, 2.5])
        );
    }

    public static function invalidPlaceholderProvider(): array
    {
        return [
            'dangling percent'       => ['SELECT %', []],
            'numbered placeholder'   => ['SELECT %1$s', ['x']],
            'flagged placeholder'    => ['SELECT %05d', [1]],
            'identifier placeholder' => ['SELECT %i', ['id']],
            'unknown placeholder'    => ['SELECT %q', ['x']],
            'binding without marker' => ['SELECT 1', [1]],
            'missing binding'        => ['SELECT %s', []],
            'extra binding'          => ['SELECT %s', ['x', 'y']],
        ];
    }

    #[DataProvider('invalidPlaceholderProvider')]
    public function testRejectsInvalidPlaceholderGrammarOrBindingCount(string $template, array $bindings): void
    {
        $this->expectException(RuntimeException::class);

        RawTemplate::compile($template, $bindings);
    }

    public function testAllowsZeroPlaceholdersWithZeroBindings(): void
    {
        $this->assertSame('SELECT 1', RawTemplate::compile('SELECT 1'));
    }

    public function testAllowsOneOptionalTrailingSemicolonAfterTrimmingOuterWhitespace(): void
    {
        $this->assertSame('SELECT %d;', RawTemplate::compile(" \nSELECT %d;\t", [1]));
    }

    public static function invalidSemicolonProvider(): array
    {
        return [
            'embedded terminator'     => ['SELECT 1; SELECT 2'],
            'two terminators'         => ['SELECT 1;;'],
            'semicolon before suffix' => ['SELECT 1; -- suffix'],
            'quoted literal'          => ["SELECT 'x;y'"],
        ];
    }

    #[DataProvider('invalidSemicolonProvider')]
    public function testRejectsEveryNonTrailingOrRepeatedSemicolon(string $template): void
    {
        $this->expectException(RuntimeException::class);

        RawTemplate::compile($template);
    }

    public function testDoesNotBlacklistSqlKeywords(): void
    {
        $this->assertSame(
            'SELECT SLEEP(%d) UNION SELECT %d',
            RawTemplate::compile('SELECT SLEEP(%d) UNION SELECT %d', [0, 1])
        );
    }

    public function testRawPreparedCompilesThenPreparesAndExecutes(): void
    {
        $wpdb            = new RawTemplateTrackingWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $rows            = [(object) ['id' => 7]];
        $wpdb->queueResult($rows);

        $result = User::query()->rawPrepared(
            'SELECT {{identifier:column}} FROM {{identifier:table}}'
                . ' WHERE {{identifier:column}} = %d ORDER BY {{identifier:column}} {{direction:sort}}',
            [7],
            ['column' => 'wp_users.id', 'table' => 'wp_users'],
            ['sort'   => 'desc']
        );

        $this->assertSame($rows, $result);
        $this->assertSame(1, $wpdb->prepareCalls);
        $this->assertSame(
            'SELECT `wp_users`.`id` FROM `wp_users` WHERE `wp_users`.`id` = 7'
                . ' ORDER BY `wp_users`.`id` DESC',
            $wpdb->last_query
        );
    }

    public function testRawPreparedBypassesPrepareWhenThereAreNoPlaceholdersOrBindings(): void
    {
        $wpdb            = new RawTemplateTrackingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        User::query()->rawPrepared('SELECT 100 %% 7');

        $this->assertSame(0, $wpdb->prepareCalls);
        $this->assertSame(['SELECT 100 % 7'], $wpdb->queries);
    }

    public function testRawPreparedBindsAValueAndUnescapesALiteralPercent(): void
    {
        $wpdb            = new RawTemplateTrackingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        User::query()->rawPrepared('SELECT %d, 100 %% 7', [7]);

        $this->assertSame(1, $wpdb->prepareCalls);
        $this->assertSame(['SELECT 7, 100 % 7'], $wpdb->queries);
    }

    public function testRawPreparedRejectsBeforePrepareOrExecution(): void
    {
        $wpdb            = new RawTemplateTrackingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        try {
            User::query()->rawPrepared('SELECT %s');
            $this->fail('A missing binding must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('placeholder', strtolower($exception->getMessage()));
            $this->assertSame(0, $wpdb->prepareCalls);
            $this->assertSame([], $wpdb->queries);
        }
    }

    public function testRawPreparedDoesNotExecuteWhenWpdbPreparationFails(): void
    {
        $wpdb                 = new RawTemplateTrackingWpdb();
        $wpdb->prepareFailure = true;
        $GLOBALS['wpdb']      = $wpdb;

        try {
            User::query()->rawPrepared('SELECT %s', ['value']);
            $this->fail('A failed wpdb preparation must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preparation failed', strtolower($exception->getMessage()));
            $this->assertSame(1, $wpdb->prepareCalls);
            $this->assertSame([], $wpdb->queries);
        }
    }

    public function testRawPreparedPreservesNonSelectResultSemantics(): void
    {
        $wpdb                = new RawTemplateTrackingWpdb();
        $wpdb->rows_affected = 3;
        $GLOBALS['wpdb']     = $wpdb;

        $result = User::query()->rawPrepared('UPDATE contacts SET active = %d', [1]);

        $this->assertSame(3, $result);
    }

    public function testUnsafeRawPreservesTheReviewedLegacyEscapeHatch(): void
    {
        $wpdb            = new RawTemplateTrackingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        User::query()->unsafeRaw(
            "SELECT `id` FROM `wp_users` WHERE `name` = %s AND 'static' = 'static' # reviewed",
            ['Ada']
        );

        $this->assertSame(1, $wpdb->prepareCalls);
        $this->assertSame(
            "SELECT `id` FROM `wp_users` WHERE `name` = 'Ada' AND 'static' = 'static' # reviewed",
            $wpdb->last_query
        );
    }

    public function testDeprecatedRawRetainsLegacyUnsafeBehavior(): void
    {
        $wpdb            = new RawTemplateTrackingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        User::query()->raw("SELECT 'legacy'");

        $this->assertSame("SELECT 'legacy'", $wpdb->last_query);
    }
}

final class RawTemplateTrackingWpdb extends FakeWpdb
{
    public $prepareCalls = 0;

    public $prepareFailure = false;

    public function prepare($query, ...$args)
    {
        $this->prepareCalls++;

        if ($this->prepareFailure) {
            return false;
        }

        return parent::prepare($query, ...$args);
    }
}
