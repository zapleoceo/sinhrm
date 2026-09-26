<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Models\User;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Http\Requests\SubmitChangeRequestRequest;
use App\Modules\People\Http\Resources\ChangeRequestResource;
use App\Modules\People\Http\Resources\EmployeeResource;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\ChangeRequestService;
use App\Modules\People\Services\EmployeeService;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Self-service: /api/me/employee (own profile incl. PII) and change requests. 404 no_employee without a link. */
final class MyEmployeeController
{
    public function __construct(
        private readonly PeopleScope $scope,
        private readonly EmployeeService $employees,
    ) {}

    public function show(Request $request): EmployeeResource
    {
        $actor = $this->actor($request);

        return EmployeeResource::for($this->employees->find($this->own($actor)->id), $this->scope->for($actor));
    }

    public function submitChange(SubmitChangeRequestRequest $request, ChangeRequestService $service): JsonResponse
    {
        $actor = $this->actor($request);
        $changeRequest = $service->submit($actor, $this->own($actor), $request->changes(), $request->comment());

        return ChangeRequestResource::for($changeRequest, $this->scope->for($actor))->response()->setStatusCode(201);
    }

    private function own(User $actor): Employee
    {
        return $this->scope->employeeOf($actor) ?? throw PeopleException::noEmployee();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
