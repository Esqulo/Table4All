<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The restaurant's public @handle, used in its profile address (/vempro/{handle}).
 * Stored lowercase, so lookups and uniqueness are case-insensitive.
 */
final class RestaurantHandle
{
    public const MIN = 3;

    public const MAX = 30;

    /** Letters, digits, "-" and "_"; starts and ends with a letter or digit; no "--" or "_-". */
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9]|[-_](?=[a-z0-9]))*$/';

    /** What the owner typed, as it will be stored: trimmed, no leading "@", lowercase. */
    public static function normalize(string $input): string
    {
        return Str::lower(ltrim(trim($input), '@'));
    }

    /** A free handle derived from the restaurant's name ("Bar do Zé" → "bar-do-ze", then "bar-do-ze-2"). */
    public static function generate(string $name, ?int $ignoreUserId = null): string
    {
        $base = trim(Str::limit(Str::slug($name), self::MAX, ''), '-');

        if (strlen($base) < self::MIN) {
            $base = 'restaurante';
        }

        $candidate = $base;

        for ($n = 2; self::taken($candidate, $ignoreUserId); $n++) {
            $suffix = '-'.$n;
            $candidate = rtrim(substr($base, 0, self::MAX - strlen($suffix)), '-').$suffix;
        }

        return $candidate;
    }

    private static function taken(string $handle, ?int $ignoreUserId): bool
    {
        return User::where('handle', $handle)
            ->when($ignoreUserId, fn ($q) => $q->whereKeyNot($ignoreUserId))
            ->exists();
    }
}
