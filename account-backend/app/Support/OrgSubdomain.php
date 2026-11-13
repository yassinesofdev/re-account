<?php

namespace App\Support;

/**
 * Org slugs double as subdomains (`<org-slug>.<domain>`): they must be a
 * DNS-safe label and must not be a reserved platform name
 * (`config('subdomains.reserved')`).
 */
class OrgSubdomain
{
    /** One DNS label: lowercase letters/digits/hyphens, 1–63 chars, no leading/trailing hyphen. */
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public const MAX_LENGTH = 63;

    public static function isDnsSafe(string $slug): bool
    {
        return preg_match(self::PATTERN, $slug) === 1;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), config('subdomains.reserved', []), true);
    }

    public static function isAllowed(string $slug): bool
    {
        return self::isDnsSafe($slug) && ! self::isReserved($slug);
    }
}
