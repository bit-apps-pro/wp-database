<?php

namespace BitApps\WPDatabase\Query;

use RuntimeException;

if (!\defined('ABSPATH')) {
    exit;
}

final class Identifier
{
    /**
     * Accepts one unqualified identifier segment.
     *
     * A leading digit is allowed because every segment is emitted backtick
     * quoted and hosts do generate WordPress prefixes such as `5c_`. An
     * all-digit segment stays invalid, matching MySQL and keeping numeric
     * literals from passing where a column is expected.
     */
    public static function assertSimple(string $value): void
    {
        if (!preg_match('/^(?![0-9]+$)[A-Za-z0-9_]+$/', $value)) {
            throw new RuntimeException('Invalid SQL identifier.');
        }
    }

    public static function quoteQualified(string $value, bool $allowWildcard = false): string
    {
        $segments = explode('.', $value);
        $last     = \count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($segment === '*' && $allowWildcard && $index === $last) {
                continue;
            }

            self::assertSimple($segment);
        }

        return implode('.', array_map(static function ($segment) {
            return $segment === '*' ? '*' : '`' . $segment . '`';
        }, $segments));
    }

    public static function quoteAlias(string $value): string
    {
        self::assertSimple($value);

        return '`' . $value . '`';
    }
}
