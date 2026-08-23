<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Query\Identifier;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversNothing]
final class IdentifierTest extends TestCase
{
    public static function simpleIdentifierProvider(): array
    {
        return [
            'letter'        => ['id'],
            'underscore'    => ['_private'],
            'mixed'         => ['Custom_Field_12'],
            'leading digit' => ['1id'],
            'digit prefix'  => ['5c_bit_pi_flows'],
        ];
    }

    #[DataProvider('simpleIdentifierProvider')]
    public function testAcceptsSimpleIdentifierSegments(string $identifier): void
    {
        Identifier::assertSimple($identifier);

        $this->addToAssertionCount(1);
    }

    public static function invalidSimpleIdentifierProvider(): array
    {
        return [
            'empty'             => [''],
            'all digits'        => ['5'],
            'empty segment'     => ['user..id'],
            'implicit alias'    => ['id alias'],
            'pre-backticked'    => ['`id`'],
            'comment'           => ['id/**/DESC'],
            'line comment'      => ['id--comment'],
            'expression'        => ['LOWER(id)'],
            'reported payload'  => ['id; DROP TABLE x'],
            'reported payload2' => ['a); DROP'],
        ];
    }

    #[DataProvider('invalidSimpleIdentifierProvider')]
    public function testRejectsInvalidSimpleIdentifierSegments(string $identifier): void
    {
        $this->expectException(RuntimeException::class);

        Identifier::assertSimple($identifier);
    }

    public static function qualifiedIdentifierProvider(): array
    {
        return [
            'simple'          => ['id', '`id`'],
            'qualified'       => ['users.id', '`users`.`id`'],
            'digit prefix'    => ['5c_bit_pi_flows.id', '`5c_bit_pi_flows`.`id`'],
            'multiple levels' => ['catalog.users.id', '`catalog`.`users`.`id`'],
        ];
    }

    #[DataProvider('qualifiedIdentifierProvider')]
    public function testQuotesEveryQualifiedIdentifierSegment(string $identifier, string $expected): void
    {
        $this->assertSame($expected, Identifier::quoteQualified($identifier));
    }

    public function testQuotesWildcardOnlyWhenExplicitlyAllowed(): void
    {
        $this->assertSame('*', Identifier::quoteQualified('*', true));
        $this->assertSame('`users`.*', Identifier::quoteQualified('users.*', true));
    }

    public static function forbiddenWildcardProvider(): array
    {
        return [
            'bare wildcard'      => ['*', false],
            'qualified wildcard' => ['users.*', false],
            'leading wildcard'   => ['*.id', true],
            'embedded wildcard'  => ['users.*.id', true],
            'partial wildcard'   => ['users.i*', true],
        ];
    }

    #[DataProvider('forbiddenWildcardProvider')]
    public function testRejectsWildcardOutsidePermittedPosition(string $identifier, bool $allowWildcard): void
    {
        $this->expectException(RuntimeException::class);

        Identifier::quoteQualified($identifier, $allowWildcard);
    }

    public static function invalidQualifiedIdentifierProvider(): array
    {
        return [
            'leading dot'    => ['.id'],
            'trailing dot'   => ['users.'],
            'empty segment'  => ['users..id'],
            'whitespace'     => ['users .id'],
            'pre-backticked' => ['`users`.id'],
            'comment'        => ['users/**/.id'],
            'expression'     => ['LOWER(users.id)'],
        ];
    }

    #[DataProvider('invalidQualifiedIdentifierProvider')]
    public function testRejectsInvalidQualifiedIdentifiers(string $identifier): void
    {
        $this->expectException(RuntimeException::class);

        Identifier::quoteQualified($identifier);
    }

    public function testQuotesOnlySimpleAliases(): void
    {
        $this->assertSame('`user_id`', Identifier::quoteAlias('user_id'));
    }

    public static function invalidAliasProvider(): array
    {
        return [
            'qualified'      => ['users.id'],
            'implicit'       => ['id alias'],
            'pre-backticked' => ['`alias`'],
            'comment'        => ['alias--'],
        ];
    }

    #[DataProvider('invalidAliasProvider')]
    public function testRejectsInvalidAliases(string $alias): void
    {
        $this->expectException(RuntimeException::class);

        Identifier::quoteAlias($alias);
    }
}
