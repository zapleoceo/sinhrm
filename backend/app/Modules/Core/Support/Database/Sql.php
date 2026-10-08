<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use InvalidArgumentException;

/**
 * Portable SQL fragments for the places where PostgreSQL and MySQL 8.4 differ (ADR 0010, dual support).
 * New code uses these (or the Laravel builder) instead of pg-only syntax: NULLS FIRST/LAST, ILIKE, ->>, @>.
 *
 * Every $expression is SQL written in code, never request text; user values travel only as bindings. A plain string must be
 * a column identifier (`col` or `table.col`) — anything else (spaces, quotes, `;`, `--`) is rejected. A computed expression
 * or a correlated subquery is passed explicitly as `new Illuminate\Database\Query\Expression('(select ...)')`.
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
     * $expression may be a select alias (e.g. a computed score) on every driver.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  list<mixed>  $bindings  bindings of $expression (for a subquery)
     *
     * @throws InvalidArgumentException a string $expression that is not a column identifier, or a bad direction
     */
    public static function orderByNullsLast(EloquentBuilder|QueryBuilder $query, string|Expression $expression, string $direction, array $bindings = []): void
    {
        self::orderByNulls($query, $expression, $direction, $bindings, last: true);
    }

    /**
     * ORDER BY $expression $direction with NULL rows before the others in both directions.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  list<mixed>  $bindings
     */
    public static function orderByNullsFirst(EloquentBuilder|QueryBuilder $query, string|Expression $expression, string $direction, array $bindings = []): void
    {
        self::orderByNulls($query, $expression, $direction, $bindings, last: false);
    }

    /**
     * WHERE $expression contains $needle, case-insensitively, wildcards in $needle literal — replaces ILIKE.
     * lower() on both sides behaves the same on PostgreSQL, MySQL (the _ci collation already ignores case) and SQLite.
     * Known divergence (ADR 0010): on MySQL (utf8mb4_0900_ai_ci) the search also ignores diacritics (й = и, é = e),
     * on PostgreSQL only case — MySQL finds more.
     * $asText casts the column to a string type first (numbers, dates), per the query's driver.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     *
     * @throws InvalidArgumentException a string $expression that is not a column identifier
     */
    public static function whereContainsCi(EloquentBuilder|QueryBuilder $query, string|Expression $expression, string $needle, string $boolean = 'and', bool $asText = false): void
    {
        $sql = self::sql($expression, $query);
        if ($asText) {
            $sql = self::castText(self::driverOf(self::grammarOf($query)), $sql);
        }
        $query->whereRaw('lower('.$sql.") like ? escape '!'", [Like::contains(mb_strtolower($needle), Like::PORTABLE)], $boolean);
    }

    /**
     * Text value of a top-level JSON key, for raw select/order by (where() should use Laravel's 'column->key').
     * PostgreSQL: column->>'key'; MySQL: json_unquote(json_extract(column, '$."key"')); SQLite: json_extract.
     *
     * JSON null differs: a key holding JSON null gives the string 'null' on MySQL (json_unquote of the JSON null literal)
     * and SQL NULL on PostgreSQL (->> returns NULL) and SQLite; a missing key is SQL NULL everywhere. Do not rely on
     * `is null` / nulls-last for keys that may hold JSON null — store the key absent, or compare with 'null' on MySQL.
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
    private static function orderByNulls(EloquentBuilder|QueryBuilder $query, string|Expression $expression, string $direction, array $bindings, bool $last): void
    {
        $direction = strtolower($direction);
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException('Direction must be asc or desc.');
        }
        $expression = self::sql($expression, $query);
        $grammar = self::grammarOf($query);
        if ($grammar instanceof PostgresGrammar || $grammar instanceof SQLiteGrammar) {
            // Native clause: PostgreSQL rejects a select alias inside an expression (case when alias ...), NULLS LAST is fine.
            $query->orderByRaw($expression.' '.$direction.($last ? ' nulls last' : ' nulls first'), $bindings);

            return;
        }
        // MySQL has no NULLS FIRST/LAST: a 0/1 null flag sorts first (MySQL accepts aliases inside the expression).
        $query->orderByRaw($expression.' is null '.($last ? 'asc' : 'desc'), $bindings)
            ->orderByRaw($expression.' '.$direction, $bindings);
    }

    /** @param  EloquentBuilder<*>|QueryBuilder  $query */
    private static function grammarOf(EloquentBuilder|QueryBuilder $query): Grammar
    {
        return ($query instanceof EloquentBuilder ? $query->getQuery() : $query)->getGrammar();
    }

    private static function driverOf(Grammar $grammar): string
    {
        return match (true) {
            $grammar instanceof PostgresGrammar => 'pgsql',
            $grammar instanceof SQLiteGrammar => 'sqlite',
            default => 'mysql',
        };
    }

    /**
     * A string must be a column identifier; an Expression is the explicit "trusted SQL written in code" opt-in.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    private static function sql(string|Expression $expression, EloquentBuilder|QueryBuilder $query): string
    {
        if ($expression instanceof Expression) {
            return (string) $expression->getValue(self::grammarOf($query));
        }
        if (preg_match(self::IDENTIFIER, $expression) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL expression: pass a column identifier, or wrap trusted SQL in an Expression.');
        }

        return $expression;
    }
}
