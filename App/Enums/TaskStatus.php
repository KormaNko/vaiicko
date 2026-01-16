<?php

namespace App\Enums;

final class TaskStatus
{
    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';

    /**
     * Return all allowed values.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return [self::PENDING, self::IN_PROGRESS, self::COMPLETED];
    }

    /**
     * Try to get a valid value from input, return null if invalid.
     *
     * @param string $value
     * @return string|null
     */
    public static function tryFrom(string $value): ?string
    {
        return in_array($value, self::values(), true) ? $value : null;
    }

    /**
     * Validate value
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}

