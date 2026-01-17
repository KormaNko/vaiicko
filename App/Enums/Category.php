<?php

namespace App\Enums;

/**
 * Deprecated: Category enum was replaced by DB-backed categories (App\Models\Category).
 * This stub exists to avoid fatal errors if some legacy code still references it.
 */
final class Category
{
    public static function values(): array
    {
        return [];
    }

    public static function tryFrom(string $value): ?string
    {
        return null;
    }

    public static function isValid(string $value): bool
    {
        return false;
    }
}
