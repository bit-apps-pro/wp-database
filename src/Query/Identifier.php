<?php

namespace BitApps\WPDatabase\Query;

use RuntimeException;

if (!\defined('ABSPATH')) {
    exit;
}

final class Identifier
{
    public static function assertSimple(string $value): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
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
