<?php

namespace BitApps\WPDatabase\Query;

use RuntimeException;

if (!\defined('ABSPATH')) {
    exit;
}

final class SqlOperator
{
    private const ALLOWED = [
        '=',
        '!=',
        '<>',
        '>',
        '<',
        '>=',
        '<=',
        'LIKE',
        'NOT LIKE',
        'IN',
        'NOT IN',
        'IS NULL',
        'IS NOT NULL',
        'BETWEEN',
    ];

    public static function normalize($operator): string
    {
        if (!\is_string($operator)) {
            throw new RuntimeException('Invalid SQL operator.');
        }

        $normalized = strtoupper($operator);
        if (!\in_array($normalized, self::ALLOWED, true)) {
            throw new RuntimeException('Invalid SQL operator.');
        }

        return $normalized;
    }
}
