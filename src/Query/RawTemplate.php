<?php

namespace BitApps\WPDatabase\Query;

use RuntimeException;

if (!\defined('ABSPATH')) {
    exit;
}

final class RawTemplate
{
    private const MARKER_PATTERN = '/\{\{(identifier|direction):([A-Za-z_][A-Za-z0-9_]*)\}\}/';

    private const FORBIDDEN_TOKENS = ["'", '"', '`', '#', '--', '/*', '*/'];

    public static function compile(
        string $template,
        array $bindings = [],
        array $identifiers = [],
        array $directions = []
    ): string {
        $template = trim($template);

        self::assertSemicolonPolicy($template);
        self::assertNoForbiddenTokens($template);

        $usedIdentifiers = [];
        $usedDirections  = [];
        $compiled        = preg_replace_callback(
            self::MARKER_PATTERN,
            static function (array $matches) use (
                $identifiers,
                $directions,
                &$usedIdentifiers,
                &$usedDirections
            ): string {
                $kind = $matches[1];
                $key  = $matches[2];

                if ($kind === 'identifier') {
                    if (!\array_key_exists($key, $identifiers) || !\is_string($identifiers[$key])) {
                        throw new RuntimeException('Missing or invalid raw-template identifier.');
                    }

                    $usedIdentifiers[$key] = true;

                    return Identifier::quoteQualified($identifiers[$key]);
                }

                if (!\array_key_exists($key, $directions) || !\is_string($directions[$key])) {
                    throw new RuntimeException('Missing or invalid raw-template direction.');
                }

                $direction = strtoupper($directions[$key]);
                if (!\in_array($direction, ['ASC', 'DESC'], true)) {
                    throw new RuntimeException('Invalid raw-template direction.');
                }

                $usedDirections[$key] = true;

                return $direction;
            },
            $template
        );

        if ($compiled === null || strpbrk($compiled, '{}') !== false) {
            throw new RuntimeException('Malformed or unsupported raw-template marker.');
        }

        if (\count($usedIdentifiers)   !== \count($identifiers)
            || \count($usedDirections) !== \count($directions)
        ) {
            throw new RuntimeException('Raw-template marker maps contain unused entries.');
        }

        self::assertPlaceholderCount($compiled, \count($bindings));

        return $compiled;
    }

    private static function assertNoForbiddenTokens(string $template): void
    {
        foreach (self::FORBIDDEN_TOKENS as $token) {
            if (strpos($template, $token) !== false) {
                throw new RuntimeException('Raw templates cannot contain quotes, backticks, or comments.');
            }
        }
    }

    private static function assertSemicolonPolicy(string $template): void
    {
        $semicolonCount = substr_count($template, ';');
        if ($semicolonCount === 0) {
            return;
        }

        if ($semicolonCount !== 1 || substr($template, -1) !== ';') {
            throw new RuntimeException('Raw templates allow only one optional trailing semicolon.');
        }
    }

    private static function assertPlaceholderCount(string $template, int $bindingCount): void
    {
        $placeholderCount = 0;
        $length           = \strlen($template);

        for ($index = 0; $index < $length; $index++) {
            if ($template[$index] !== '%') {
                continue;
            }

            if ($index + 1 >= $length) {
                throw new RuntimeException('Raw template contains a dangling percent sign.');
            }

            $specifier = $template[++$index];
            if ($specifier === '%') {
                continue;
            }

            if (!\in_array($specifier, ['s', 'd', 'f', 'F'], true)) {
                throw new RuntimeException('Raw template contains an unsupported placeholder.');
            }

            $placeholderCount++;
        }

        if ($placeholderCount !== $bindingCount) {
            throw new RuntimeException('Raw-template placeholder and binding counts must match exactly.');
        }
    }
}
