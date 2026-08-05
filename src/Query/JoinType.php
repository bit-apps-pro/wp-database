<?php

namespace BitApps\WPDatabase\Query;

use RuntimeException;

if (!\defined('ABSPATH')) {
    exit;
}

final class JoinType
{
    private const ALLOWED = ['INNER', 'LEFT', 'RIGHT', 'CROSS'];

    public static function normalize($type): string
    {
        if (!\is_string($type)) {
            throw new RuntimeException('Invalid SQL join type.');
        }

        $normalized = strtoupper($type);
        if (!\in_array($normalized, self::ALLOWED, true)) {
            throw new RuntimeException('Invalid SQL join type.');
        }

        return $normalized;
    }
}
