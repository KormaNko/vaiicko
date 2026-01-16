<?php

namespace App\Enums;

final class Category
{
    public const SCHOOL = 'school';
    public const WORK = 'work';
    public const FREE_TIME = 'free_time';
    public const PERSONAL = 'personal';
    public const OTHER = 'other';

    /**
     * Return all allowed values.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return [self::SCHOOL, self::WORK, self::FREE_TIME, self::PERSONAL, self::OTHER];
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

