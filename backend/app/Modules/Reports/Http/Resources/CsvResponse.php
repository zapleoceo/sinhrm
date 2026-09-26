<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Reports\Support\Csv;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A streamed CSV download (rows are written as they are produced; never inline). */
final class CsvResponse
{
    /**
     * @param  list<string>  $columns
     * @param  iterable<array<string, scalar|null>>  $rows
     */
    public static function make(string $name, array $columns, iterable $rows): StreamedResponse
    {
        $filename = (preg_replace('/[^a-z0-9_-]+/i', '_', $name) ?: 'report').'-'.date('Y-m-d').'.csv';

        return new StreamedResponse(static function () use ($columns, $rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            Csv::write($out, $columns, $rows);
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
