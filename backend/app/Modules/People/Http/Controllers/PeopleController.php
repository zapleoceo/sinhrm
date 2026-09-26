<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Models\User;
use App\Modules\People\Http\Requests\ListPeopleRequest;
use App\Modules\People\Http\Requests\OrgChartRequest;
use App\Modules\People\Http\Requests\SaveEmployeeRequest;
use App\Modules\People\Http\Requests\TerminateEmployeeRequest;
use App\Modules\People\Http\Resources\EmployeeResource;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\EmployeeService;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Directory, profile, admin edits, org chart. Visibility tiers: EmployeeResource + PeopleScope. */
final class PeopleController
{
    public function __construct(
        private readonly EmployeeService $service,
        private readonly PeopleScope $scope,
    ) {}

    /** Directory tier only, for every active user. */
    public function index(ListPeopleRequest $request): AnonymousResourceCollection
    {
        return EmployeeResource::collection($this->service->list($request->filter()));
    }

    public function show(Request $request, Employee $employee): EmployeeResource
    {
        return EmployeeResource::for($this->service->find($employee->id), $this->scope->for($this->actor($request)));
    }

    public function store(SaveEmployeeRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $employee = $this->service->create($actor, $request->attributesToSave());

        return EmployeeResource::for($employee, $this->scope->for($actor))->response()->setStatusCode(201);
    }

    public function update(SaveEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $actor = $this->actor($request);

        return EmployeeResource::for($this->service->update($actor, $employee, $request->attributesToSave()), $this->scope->for($actor));
    }

    public function terminate(TerminateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $actor = $this->actor($request);
        $employee = $this->service->terminate($actor, $employee, $request->firedAt(), $request->reason());

        return EmployeeResource::for($employee, $this->scope->for($actor));
    }

    /** ?mine=1 — the caller's own subtree (a manager's team); ?root_id — any subtree; ?branch_id. */
    public function orgChart(OrgChartRequest $request): JsonResponse
    {
        $root = $request->rootId();
        if ($request->mine()) {
            $root = $this->scope->for($this->actor($request))->selfId ?? 0;
        }

        return new JsonResponse(['data' => $this->service->orgChart($request->branchId(), $root)]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
