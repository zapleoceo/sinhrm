<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Core\Http\Responses\Download;
use App\Modules\Core\Support\Export\Csv;
use App\Modules\Core\Support\UserTime;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A streamed CSV download (rows are written as they are produced; never inline). */
final class CsvResponse
{
    /**
     * @param  list<string>  $columns
     * @param  iterable<array<string, scalar|null>>  $rows
     * @param  array<string, int|float|null>|null  $total
     */
    public static function make(string $name, array $columns, iterable $rows, ?array $total = null): StreamedResponse
    {
        // The date is the user's day (Europe/Kyiv): a report exported right after midnight in Kyiv is today's file.
        $filename = (preg_replace('/[^a-z0-9_-]+/i', '_', $name) ?: 'report').'-'.UserTime::today()->toDateString().'.csv';

        return new StreamedResponse(static function () use ($columns, $rows, $total): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            Csv::write($out, $columns, $rows, $total);
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => Download::disposition($filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
