<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Modules\GoogleWorkspace\Contracts\SheetsClient;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Support\GoogleApi;

final readonly class GoogleSheetsClient implements SheetsClient
{
    public const string BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    public function __construct(private GoogleApi $api) {}

    public function values(string $spreadsheetId, string $range): array
    {
        $json = $this->api->get(
            GoogleService::Sheets,
            self::BASE.'/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($range),
            ['majorDimension' => 'ROWS', 'valueRenderOption' => 'FORMATTED_VALUE'],
        );
        $rows = [];
        foreach (is_array($json['values'] ?? null) ? $json['values'] : [] as $row) {
            $rows[] = is_array($row)
                ? array_values(array_map(static fn (mixed $cell): string => is_scalar($cell) ? trim((string) $cell) : '', $row))
                : [];
        }

        return $rows;
    }
}
