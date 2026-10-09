<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use InvalidArgumentException;

/**
 * SQL fragments the Laravel builder does not express on MySQL 8.4 — the only supported database (ADR 0010).
 * New code uses these (or the Laravel builder) instead of hand-written variants: NULLS FIRST/LAST does not exist
 * in MySQL, a case-insensitive "contains" needs literal wildcards, a JSON key read in select/order needs json_unquote.
 *
 * Every $expression is SQL written in code, never request text; user values travel only as bindings. A plain string must be
 * a column identifier (`col` or `table.col`) — anything else (spaces, quotes, `;`, `--`) is rejected. A computed expression
 * or a correlated subquery is passed explicitly as `new Illuminate\Database\Query\Expression('(select ...)')`.
 *
 * Upserts are not wrapped: Builder::upsert()/insertOrIgnore()/insertGetId() work as is. On MySQL upsert() ignores
 * $uniqueBy and reacts to ANY unique index of the table — keep exactly one unique key on upserted tables.
 * JSON in where(): Laravel's 'column->key', whereJsonContains() and whereJsonLength().
 */
final class Sql
{
    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /** Drivers accepted by jsonText()/castText(): MySQL and its wire-compatible MariaDB driver of Laravel. */
    private const array DRIVERS = ['mysql', 'mariadb'];

    /**
     * ORDER BY $expression $direction with NULL rows after the others in both directions
     * (MySQL alone sorts NULL first on ASC and last on DESC). $expression may be a select alias (e.g. a computed score).
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
     * WHERE $expression contains $needle, case-insensitively, wildcards in $needle literal.
     * lower() on both sides keeps the result independent of the column collation (a _bin column stays case-insensitive too).
     * With utf8mb4_0900_ai_ci the search also ignores Latin diacritics (é = e); Cyrillic й is not folded into и.
     * $asText casts the column to a string first (numbers, dates).
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     *
     * @throws InvalidArgumentException a string $expression that is not a column identifier
     */
    public static function whereContainsCi(EloquentBuilder|QueryBuilder $query, string|Expression $expression, string $needle, string $boolean = 'and', bool $asText = false): void
    {
        $sql = self::sql($expression, $query);
        if ($asText) {
            $sql = self::castText('mysql', $sql);
        }
        $query->whereRaw('lower('.$sql.") like ? escape '!'", [Like::contains(mb_strtolower($needle), Like::PORTABLE)], $boolean);
    }

    /**
     * Text value of a top-level JSON key, for raw select/order by (where() should use Laravel's 'column->key'):
     * json_unquote(json_extract(column, '$."key"')) — the same expression Laravel compiles for 'column->key'.
     * A key holding JSON null gives the string 'null', a missing key gives SQL NULL: do not rely on `is null` /
     * nulls-last for keys that may hold JSON null — store the key absent instead.
     *
     * @param  string  $driver  'mysql' (or 'mariadb'); kept in the signature so a call site states its driver explicitly
     */
    public static function jsonText(string $driver, string $column, string $key): string
    {
        if (preg_match(self::IDENTIFIER, $column) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
            throw new InvalidArgumentException('Unsafe JSON column or key.');
        }
        self::assertDriver($driver);

        return "json_unquote(json_extract({$column}, '$.\"{$key}\"'))";
    }

    /** cast($expression as char($length)) — MySQL has no CAST(.. AS VARCHAR/TEXT). */
    public static function castText(string $driver, string $expression, int $length = 255): string
    {
        self::assertDriver($driver);

        return "cast({$expression} as char({$length}))";
    }

    /**
     * MySQL has no NULLS FIRST/LAST: a 0/1 null flag sorts first, then the value (aliases are accepted in both terms).
     *
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
        $query->orderByRaw($expression.' is null '.($last ? 'asc' : 'desc'), $bindings)
            ->orderByRaw($expression.' '.$direction, $bindings);
    }

    private static function assertDriver(string $driver): void
    {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException("Unsupported driver {$driver}: SinHRM runs on MySQL 8.4 only (ADR 0010).");
        }
    }

    /** @param  EloquentBuilder<*>|QueryBuilder  $query */
    private static function grammarOf(EloquentBuilder|QueryBuilder $query): Grammar
    {
        return ($query instanceof EloquentBuilder ? $query->getQuery() : $query)->getGrammar();
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
