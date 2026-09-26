<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Models\User;
use App\Modules\People\Http\Requests\HireRequest;
use App\Modules\People\Http\Resources\EmployeeResource;
use App\Modules\People\Services\HireService;
use App\Modules\Recruiting\Models\Application;
use Illuminate\Http\JsonResponse;

/** POST /api/applications/{application}/hire — 201 created, 200 when the employee already exists (idempotent). */
final class HireController
{
    public function __invoke(HireRequest $request, Application $application, HireService $service): JsonResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);
        $result = $service->hire($actor, $application, $request->hiredAt());

        return (new EmployeeResource($result->employee->loadMissing(['branch', 'department', 'position', 'manager'])))
            ->additional(['meta' => ['created' => $result->created]])
            ->response()
            ->setStatusCode($result->created ? 201 : 200);
    }
}
