<?php

namespace App\Enums;

/**
 * Simple enum-like class for task statuses.
 * Kept minimal and backwards-compatible with string usage used across the app.
 */
class TaskStatus
{
    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';

    /**
     * Check if a value is a valid status
     * @param string|null $value
     * @return bool
     */
    public static function isValid(?string $value): bool
    {
        if ($value === null) return false;
        $vals = [self::PENDING, self::IN_PROGRESS, self::COMPLETED];
        return in_array($value, $vals, true);
    }
}

