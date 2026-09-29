<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

/**
 * The «Total» row of a report table, driven by a per-column hint (column key `total`):
 * - `sum`: plain sum (counts, hours, days, money);
 * - `ratio` + `of: [numerator, denominator]`: the true overall ratio from the summed columns (×100 for a percent);
 * - `avg-weighted` + `weight: column`: an average weighted by the row count column;
 * - `none` (the default): percentages, medians, dates, text — no meaningful total.
 * A null cell means a suppressed group (anonymity) or an unknown value: the total of that column is then null
 * as well, so a hidden group can never be recovered as «total minus visible rows».
 */
final class Totals
{
    public const string SUM = 'sum';
    public const string RATIO = 'ratio';
    public const string AVG_WEIGHTED = 'avg-weighted';
    public const string NONE = 'none';

    /** Fewer rows than this: the table has no total row. */
    public const int MIN_ROWS = 2;

    /**
     * @param  list<array{key: string, type?: string, total?: string, of?: list<string>, weight?: string}>  $columns
     * @param  list<array<string, scalar|null>>  $rows
     * @return array<string, int|float|null>|null one value per column key (null = «—»); null = no total row
     */
    public static function row(array $columns, array $rows): ?array
    {
        if (count($rows) < self::MIN_ROWS) {
            return null;
        }
        $total = [];
        foreach ($columns as $c) {
            $total[$c['key']] = match ($c['total'] ?? self::NONE) {
                self::SUM => self::sum($rows, $c['key']),
                self::RATIO => self::ratio($rows, $c['of'] ?? [], self::isPercent($c)),
                self::AVG_WEIGHTED => self::weighted($rows, $c['key'], $c['weight'] ?? ''),
                default => null,
            };
        }

        return $total;
    }

    /** @param  list<array<string, scalar|null>>  $rows */
    private static function sum(array $rows, string $key): int|float|null
    {
        $sum = 0;
        foreach ($rows as $r) {
            $v = $r[$key] ?? null;
            if (! is_int($v) && ! is_float($v) && ! (is_string($v) && is_numeric($v))) {
                return null;
            }
            $sum += is_string($v) ? (float) $v : $v;
        }

        return is_float($sum) ? round($sum, 2) : $sum;
    }

    /**
     * @param  list<array<string, scalar|null>>  $rows
     * @param  list<string>  $of
     */
    private static function ratio(array $rows, array $of, bool $percent): ?float
    {
        if (count($of) !== 2) {
            return null;
        }
        $num = self::sum($rows, $of[0]);
        $den = self::sum($rows, $of[1]);
        if ($num === null || $den === null || $den == 0) {
            return null;
        }

        return $percent ? round($num / $den * 100, 1) : round($num / $den, 2);
    }

    /** @param  list<array<string, scalar|null>>  $rows */
    private static function weighted(array $rows, string $key, string $weight): ?float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($rows as $r) {
            $v = $r[$key] ?? null;
            $w = $r[$weight] ?? null;
            if (! is_numeric($v) || ! is_numeric($w)) {
                return null;
            }
            $sum += (float) $v * (float) $w;
            $weights += (float) $w;
        }

        return $weights == 0 ? null : round($sum / $weights, 1);
    }

    /** @param  array{key: string, type?: string}  $column */
    private static function isPercent(array $column): bool
    {
        return ($column['type'] ?? '') === 'percent' || str_ends_with($column['key'], '_pct');
    }
}
