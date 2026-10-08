<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Controllers;

use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\People\Contracts\EmployeeLookup;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\TimeOff\Http\Requests\AdjustBalanceRequest;
use App\Modules\TimeOff\Http\Requests\EmployeeScopedRequest;
use App\Modules\TimeOff\Http\Resources\SettingsResources;
use App\Modules\TimeOff\Services\BalanceService;
use App\Modules\TimeOff\Services\EmployeeResolver;
use App\Modules\TimeOff\Services\LeaveSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/** Balances per leave type (own, or ?employee_id for admin / manager above), ledger history, admin adjustments. */
final class BalanceController
{
    use ResolvesActor;

    public function __construct(
        private readonly BalanceService $balances,
        private readonly PeopleAccess $scope,
        private readonly EmployeeResolver $resolver,
    ) {}

    public function index(EmployeeScopedRequest $request): JsonResponse
    {
        $employee = $this->resolver->resolve($this->scope->for($this->actor($request)), $request->employeeId());

        return new JsonResponse([
            'data' => $this->balances->balances($employee, Carbon::now()),
            'meta' => ['employee' => ['id' => $employee->id, 'full_name' => $employee->full_name]],
        ]);
    }

    public function history(EmployeeScopedRequest $request): JsonResponse
    {
        $employee = $this->resolver->resolve($this->scope->for($this->actor($request)), $request->employeeId());

        return new JsonResponse(['data' => array_map(
            SettingsResources::ledger(...),
            $this->balances->history($employee, $request->leaveTypeId()),
        )]);
    }

    public function adjust(AdjustBalanceRequest $request, EmployeeLookup $employees, LeaveSettingsService $settings): JsonResponse
    {
        $employee = $employees->find($request->integer('employee_id'));
        $type = $settings->findType($request->integer('leave_type_id'));
        $this->balances->adjust($this->actor($request), $employee, $type, $request->float('delta'), $request->comment());

        return new JsonResponse(['data' => $this->balances->balances($employee, Carbon::now())], 201);
    }
}
