<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Http\Requests\SaveCompensationRequest;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\CompensationService;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Compensation: HR staff (people-manage gate on the routes) read/add; the employee reads own via /me/employee/compensation. */
final class CompensationController
{
    use ResolvesActor;

    public function __construct(private readonly CompensationService $service) {}

    public function index(Employee $employee): JsonResponse
    {
        return new JsonResponse(['data' => $this->service->payload($employee)]);
    }

    public function store(SaveCompensationRequest $request, Employee $employee): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $this->service->add($this->actor($request), $employee, $data);

        return new JsonResponse(['data' => $this->service->payload($employee)], 201);
    }

    public function mine(Request $request, PeopleScope $scope): JsonResponse
    {
        $employee = $scope->employeeOf($this->actor($request)) ?? throw PeopleException::noEmployee();

        return new JsonResponse(['data' => $this->service->payload($employee)]);
    }
}
