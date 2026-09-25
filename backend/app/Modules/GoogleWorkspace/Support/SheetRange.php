<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Support;

/** Spreadsheet URL → id, and A1 ranges for the importer (columns A..Z). */
final class SheetRange
{
    public const string LAST_COLUMN = 'Z';

    /** https://docs.google.com/spreadsheets/d/<id>/... → id, or null for anything else. */
    public static function spreadsheetId(string $url): ?string
    {
        return preg_match('#^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]{20,128})(?:[/?\#]|$)#', trim($url), $m) === 1
            ? $m[1] : null;
    }

    /** Rows $from..$to (1-based) of the sheet; empty sheet name = the first sheet. */
    public static function rows(string $sheet, int $from, int $to): string
    {
        $cells = 'A'.$from.':'.self::LAST_COLUMN.$to;

        return $sheet === '' ? $cells : "'".str_replace("'", "''", $sheet)."'!".$cells;
    }
}
