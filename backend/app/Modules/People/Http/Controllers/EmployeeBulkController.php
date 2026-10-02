<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Http\Responses\Download;
use App\Modules\Core\Support\Export\Csv;
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
                $keyed = [];
                foreach ($rows as $row) {
                    $keyed[] = array_combine(self::CSV_COLUMNS, $row);
                }
                // The shared CSV writer: UTF-8 BOM, header, formula-injection guard (as in Reports).
                Csv::write($out, self::CSV_COLUMNS, $keyed);
                fclose($out);
            }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => Download::disposition('employees.csv')]);
        }
        $actor = $request->user();
        assert($actor instanceof User);

        return new JsonResponse(['data' => $service->update($actor, $request->ids(), $request->change())]);
    }
}
