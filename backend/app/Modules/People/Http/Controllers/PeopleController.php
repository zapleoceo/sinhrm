<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\People\Http\Requests\ListPeopleRequest;
use App\Modules\People\Http\Requests\OrgChartRequest;
use App\Modules\People\Http\Requests\RestoreEmployeeRequest;
use App\Modules\People\Http\Requests\SaveEmployeeRequest;
use App\Modules\People\Http\Requests\TerminateEmployeeRequest;
use App\Modules\People\Http\Resources\EmployeeResource;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\EmployeeService;
use App\Modules\People\Services\PeopleScope;
use App\Modules\People\Services\TerminationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Directory, profile, admin edits, termination/restore, org chart. Visibility tiers: EmployeeResource + PeopleScope. */
final class PeopleController
{
    use ResolvesActor;

    public function __construct(
        private readonly EmployeeService $service,
        private readonly PeopleScope $scope,
        private readonly TerminationService $terminations,
    ) {}

    /** Directory tier only, for every active user. */
    public function index(ListPeopleRequest $request): AnonymousResourceCollection
    {
        return EmployeeResource::collection($this->service->list($this->scope->for($this->actor($request)), $request->filter()));
    }

    public function show(Request $request, Employee $employee): EmployeeResource
    {
        $ctx = $this->scope->for($this->actor($request));

        return EmployeeResource::for($this->service->findVisible($ctx, $employee->id), $ctx);
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

    /** HR or a manager above (403 otherwise); a terminated employee outside the caller's view is 404. */
    public function terminate(TerminateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $actor = $this->actor($request);
        $ctx = $this->scope->for($actor);
        $employee = $this->service->findVisible($ctx, $employee->id);

        return EmployeeResource::for($this->terminations->terminate($ctx, $actor, $employee, $request->firedAt(), $request->reason()), $ctx);
    }

    /** Cancel a scheduled termination before its date: same callers as terminate. */
    public function cancelTermination(Request $request, Employee $employee): EmployeeResource
    {
        $actor = $this->actor($request);
        $ctx = $this->scope->for($actor);
        $employee = $this->service->findVisible($ctx, $employee->id);

        return EmployeeResource::for($this->terminations->cancel($ctx, $actor, $employee), $ctx);
    }

    /** HR only (route gate). */
    public function restore(RestoreEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $actor = $this->actor($request);

        return EmployeeResource::for($this->terminations->restore($actor, $employee, $request->placement()), $this->scope->for($actor));
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
}
