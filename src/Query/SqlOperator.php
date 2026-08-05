<?php

namespace BitApps\WPDatabase\Query;

use RuntimeException;

if (!\defined('ABSPATH')) {
    exit;
}

final class SqlOperator
{
    private const BINARY = [
        '=',
        '!=',
        '<>',
        '>',
        '<',
        '>=',
        '<=',
        'LIKE',
        'NOT LIKE',
    ];

    private const LIST = ['IN', 'NOT IN'];

    private const UNARY = ['IS NULL', 'IS NOT NULL'];

    private const RANGE = ['BETWEEN'];

    public static function normalizeBinary($operator): string
    {
        return self::normalize($operator, self::BINARY);
    }

    public static function normalizeList($operator): string
    {
        return self::normalize($operator, self::LIST);
    }

    public static function normalizeUnary($operator): string
    {
        return self::normalize($operator, self::UNARY);
    }

    public static function normalizeRange($operator): string
    {
        return self::normalize($operator, self::RANGE);
    }

    private static function normalize($operator, array $allowed): string
    {
        if (!\is_string($operator)) {
            throw new RuntimeException('Invalid SQL operator.');
        }

        $normalized = strtoupper($operator);
        if (!\in_array($normalized, $allowed, true)) {
            throw new RuntimeException('Invalid SQL operator.');
        }

        return $normalized;
    }
}
