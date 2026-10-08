<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Database;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * Portable SQL fragments for the places where PostgreSQL and MySQL 8.4 differ (ADR 0010, dual support).
 * New code uses these (or the Laravel builder) instead of pg-only syntax: NULLS FIRST/LAST, ILIKE, ->>, @>.
 *
 * Every $expression is SQL written in code (a column or a correlated subquery from a whitelist), never request text;
 * user values travel only as bindings.
 *
 * Upserts are not wrapped: Builder::upsert()/insertOrIgnore()/insertGetId() are portable. On MySQL upsert() ignores
 * $uniqueBy and reacts to ANY unique index of the table — keep exactly one unique key on upserted tables.
 * JSON in where(): Laravel's 'column->key', whereJsonContains() and whereJsonLength() compile per driver.
 */
final class Sql
{
    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /**
     * ORDER BY $expression $direction with NULL rows after the others in both directions
     * (PostgreSQL sorts NULL first on DESC, MySQL first on ASC — neither matches without this).
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  list<mixed>  $bindings  bindings of $expression (for a subquery)
     */
    public static function orderByNullsLast(EloquentBuilder|QueryBuilder $query, string $expression, string $direction, array $bindings = []): void
    {
        self::orderByNulls($query, $expression, $direction, $bindings, last: true);
    }

    /**
     * ORDER BY $expression $direction with NULL rows before the others in both directions.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  list<mixed>  $bindings
     */
    public static function orderByNullsFirst(EloquentBuilder|QueryBuilder $query, string $expression, string $direction, array $bindings = []): void
    {
        self::orderByNulls($query, $expression, $direction, $bindings, last: false);
    }

    /**
     * WHERE $expression contains $needle, case-insensitively, wildcards in $needle literal — replaces ILIKE.
     * lower() on both sides behaves the same on PostgreSQL, MySQL (the _ci collation already ignores case) and SQLite.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    public static function whereContainsCi(EloquentBuilder|QueryBuilder $query, string $expression, string $needle, string $boolean = 'and'): void
    {
        $query->whereRaw('lower('.$expression.") like ? escape '!'", [Like::contains(mb_strtolower($needle), Like::PORTABLE)], $boolean);
    }

    /**
     * Text value of a top-level JSON key, for raw select/order by (where() should use Laravel's 'column->key').
     * PostgreSQL: column->>'key'; MySQL: json_unquote(json_extract(column, '$."key"')); SQLite: json_extract.
     */
    public static function jsonText(string $driver, string $column, string $key): string
    {
        if (preg_match(self::IDENTIFIER, $column) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
            throw new InvalidArgumentException('Unsafe JSON column or key.');
        }

        return match ($driver) {
            'pgsql' => "{$column}->>'{$key}'",
            'mysql', 'mariadb' => "json_unquote(json_extract({$column}, '$.\"{$key}\"'))",
            'sqlite' => "json_extract({$column}, '$.\"{$key}\"')",
            default => throw new InvalidArgumentException("Unsupported driver {$driver}."),
        };
    }

    /**
     * cast($expression as <string type>) — MySQL has no CAST(.. AS VARCHAR/TEXT), only CHAR(n).
     * $length bounds the result on PostgreSQL/MySQL (SQLite ignores it).
     */
    public static function castText(string $driver, string $expression, int $length = 255): string
    {
        return match ($driver) {
            'pgsql' => "cast({$expression} as varchar({$length}))",
            'mysql', 'mariadb' => "cast({$expression} as char({$length}))",
            'sqlite' => "cast({$expression} as text)",
            default => throw new InvalidArgumentException("Unsupported driver {$driver}."),
        };
    }

    /**
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  list<mixed>  $bindings
     */
    private static function orderByNulls(EloquentBuilder|QueryBuilder $query, string $expression, string $direction, array $bindings, bool $last): void
    {
        $direction = strtolower($direction);
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException('Direction must be asc or desc.');
        }
        // A 0/1 flag sorts first: portable on every driver, unlike NULLS FIRST/LAST (pg/sqlite only).
        $query->orderByRaw('case when '.$expression.' is null then '.($last ? '1 else 0' : '0 else 1').' end', $bindings)
            ->orderByRaw($expression.' '.$direction, $bindings);
    }
}
