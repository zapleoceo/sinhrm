<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

/**
 * CSV output that spreadsheets cannot execute (CSV / formula injection, OWASP): a text cell starting with
 * =, +, -, @ (or a tab / carriage return, which some programs strip before evaluating) is prefixed with a single
 * quote, so it is shown as text. Numbers stay numbers (a negative amount is not text and is not prefixed).
 */
final class Csv
{
    private const array DANGEROUS = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value !== '' && in_array($value[0], self::DANGEROUS, true) ? "'".$value : $value;
    }

    /**
     * Writes rows to the stream: a header of the column keys, then one line per row (RFC 4180 quoting by fputcsv).
     *
     * @param  resource  $out
     * @param  list<string>  $columns
     * @param  iterable<array<string, scalar|null>>  $rows
     */
    public static function write($out, array $columns, iterable $rows): void
    {
        // UTF-8 BOM: Excel opens Cyrillic text correctly.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(self::cell(...), $columns), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn (string $c): string => self::cell($row[$c] ?? null), $columns), ',', '"', '');
        }
    }
}
