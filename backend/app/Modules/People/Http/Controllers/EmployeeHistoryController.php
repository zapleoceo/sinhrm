<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\Audit\Http\Requests\HistoryRequest;
use App\Modules\Audit\Http\Resources\AuditEntryResource;
use App\Modules\Audit\Services\AuditService;
use App\Modules\People\Models\Employee;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Employee profile "History" tab. Route is inside the people-manage group (HR staff): the profile itself is
 * visible to colleagues, its change history is not.
 */
final class EmployeeHistoryController
{
    public function __construct(private readonly AuditService $audit) {}

    public function __invoke(HistoryRequest $request, Employee $employee): AnonymousResourceCollection
    {
        return AuditEntryResource::collection($this->audit->history(['employee' => [$employee->id]], $request->page(), $request->perPage()));
    }
}
