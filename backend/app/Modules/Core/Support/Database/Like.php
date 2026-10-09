<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Database;

/**
 * Patterns for SQL LIKE built from user input: the wildcards % and _ (and the escape character itself) are escaped,
 * so a search for "50%" or "a_b" matches those characters literally.
 *
 * Two escape characters are in use, and the SQL must match the one the pattern was built with:
 *  - Like::BACKSLASH (default) — for `like ?` without an ESCAPE clause (MySQL escapes with a backslash by default;
 *    sql_mode in config/database.php has no NO_BACKSLASH_ESCAPES);
 *  - Like::PORTABLE ("!") — for `like ? escape '!'` (explicit, independent of sql_mode).
 * Lower-casing stays with the caller (lower(col) like ?; the collation is case-insensitive anyway, ADR 0010).
 */
final class Like
{
    public const string BACKSLASH = '\\';

    public const string PORTABLE = '!';

    /** $value with %, _ and the escape character escaped. */
    public static function escape(string $value, string $escape = self::BACKSLASH): string
    {
        return str_replace([$escape, '%', '_'], [$escape.$escape, $escape.'%', $escape.'_'], $value);
    }

    /** "%value%": the value anywhere in the column. */
    public static function contains(string $value, string $escape = self::BACKSLASH): string
    {
        return '%'.self::escape($value, $escape).'%';
    }

    /** "value%": the column starts with the value. */
    public static function startsWith(string $value, string $escape = self::BACKSLASH): string
    {
        return self::escape($value, $escape).'%';
    }
}
