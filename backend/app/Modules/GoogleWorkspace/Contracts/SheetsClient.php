<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Contracts;

use App\Modules\GoogleWorkspace\Exceptions\GoogleException;

/** Google Sheets v4, read-only. */
interface SheetsClient
{
    /**
     * spreadsheets.values.get of an A1 range, rows as lists of strings (trailing empty cells/rows are omitted by
     * Google).
     *
     * @return list<list<string>>
     *
     * @throws GoogleException
     */
    public function values(string $spreadsheetId, string $range): array;
}
