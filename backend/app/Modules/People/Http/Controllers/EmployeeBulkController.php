<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Models\User;
use App\Modules\People\Http\Requests\BulkEmployeesRequest;
use App\Modules\People\Services\EmployeeBulkService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** POST /people/bulk → {data: [{id, ok, error}]}; action=export → text/csv. */
final class EmployeeBulkController
{
    private const array CSV_COLUMNS = ['id', 'full_name', 'work_email', 'position', 'department', 'branch', 'manager', 'hired_at', 'status'];

    public function __invoke(BulkEmployeesRequest $request, EmployeeBulkService $service): JsonResponse|StreamedResponse
    {
        if ($request->action() === 'export') {
            $rows = $service->exportRows($request->ids());

            return new StreamedResponse(static function () use ($rows): void {
                $out = fopen('php://output', 'wb');
                if ($out === false) {
                    return;
                }
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, self::CSV_COLUMNS, ',', '"', '');
                foreach ($rows as $row) {
                    // Formula injection guard, as in Reports CSV.
                    fputcsv($out, array_map(static fn (string|int|null $v): string => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : (string) $v, $row), ',', '"', '');
                }
                fclose($out);
            }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="employees.csv"']);
        }
        $actor = $request->user();
        assert($actor instanceof User);

        return new JsonResponse(['data' => $service->update($actor, $request->ids(), $request->change())]);
    }
}
