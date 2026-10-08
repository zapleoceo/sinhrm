<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Transfer;

use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/**
 * Turns a cell into a driver-neutral string so the same value read from PostgreSQL and from MySQL compares equal,
 * while any real change of content does not. Pure; used by the copier (write form) and the reconciler (compare form).
 *
 * @phpstan-import-type Column from SchemaInspector
 */
final class ValueCanonicalizer
{
    public const string NULL = "\0NULL";

    /**
     * Comparison kind of a target column: bool | json | datetime | float | decimal | binary | text.
     *
     * @param  Column  $column
     */
    public static function kind(array $column): string
    {
        return match (true) {
            $column['source'] === 'boolean' || $column['column_type'] === 'tinyint(1)' => 'bool',
            $column['type'] === 'json' => 'json',
            in_array($column['type'], ['timestamp', 'datetime'], true) => 'datetime',
            in_array($column['type'], ['float', 'double', 'real'], true) => 'float',
            $column['type'] === 'decimal' => 'decimal',
            in_array($column['type'], ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'], true) => 'binary',
            default => 'text',
        };
    }

    /** Value as written to MySQL: booleans as 0/1, timestamptz in UTC, bytea streams read out; everything else as is. */
    public static function forTarget(mixed $value, string $sourceType): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            return (string) stream_get_contents($value);
        }
        if ($sourceType === 'boolean') {
            return self::bool($value) === '1' ? 1 : 0;
        }
        if ($sourceType === 'timestamp with time zone') {
            return self::datetime((string) $value);
        }

        return $value;
    }

    /** Canonical comparison form; callers hash it and never print it. */
    public static function canonical(mixed $value, string $kind): string
    {
        if ($value === null) {
            return self::NULL;
        }
        if (is_resource($value)) {
            $value = (string) stream_get_contents($value);
        }

        return match ($kind) {
            'bool' => self::bool($value),
            'json' => self::json((string) $value),
            'datetime' => self::datetime((string) $value),
            'float' => sprintf('%.15g', (float) $value),
            'decimal' => self::decimal((string) $value),
            default => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
        };
    }

    private static function bool(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === 't' || $value === 'true') {
            return '1';
        }
        if ($value === 'f' || $value === 'false') {
            return '0';
        }

        return (string) (int) $value;
    }

    /** UTC, microseconds always present: PostgreSQL trims trailing zeros of fractions, MySQL pads them. */
    private static function datetime(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function decimal(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '-0' || $value === '' ? '0' : $value;
    }

    /** MySQL re-serialises JSON (key order, spaces): compare decoded, keys sorted, objects and arrays kept distinct. */
    private static function json(string $value): string
    {
        $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);

        return (string) json_encode(self::sortKeys($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $item): mixed
    {
        if ($item instanceof stdClass) {
            $properties = get_object_vars($item);
            ksort($properties, SORT_STRING);

            return (object) array_map(self::sortKeys(...), $properties);
        }

        return is_array($item) ? array_map(self::sortKeys(...), $item) : $item;
    }
}
