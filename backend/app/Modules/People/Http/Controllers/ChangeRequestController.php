<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Models\User;
use App\Modules\People\Http\Requests\DecisionRequest;
use App\Modules\People\Http\Requests\ListChangeRequestsRequest;
use App\Modules\People\Http\Resources\ChangeRequestResource;
use App\Modules\People\Models\EmployeeChangeRequest;
use App\Modules\People\Services\ChangeRequestService;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** /api/people/change-requests: list (admin: all; others: own + below them), approve / reject (admin or manager above). */
final class ChangeRequestController
{
    public function __construct(
        private readonly ChangeRequestService $service,
        private readonly PeopleScope $scope,
    ) {}

    public function index(ListChangeRequestsRequest $request): AnonymousResourceCollection
    {
        $ctx = $this->scope->for($this->actor($request));
        $page = $this->service->list($ctx, $request->status(), $request->employeeId(), $request->perPage());

        return ChangeRequestResource::collection($page->through(
            static fn (EmployeeChangeRequest $r): ChangeRequestResource => ChangeRequestResource::for($r, $ctx),
        ));
    }

    public function approve(DecisionRequest $request, EmployeeChangeRequest $changeRequest): ChangeRequestResource
    {
        return $this->decide($request, $changeRequest, true);
    }

    public function reject(DecisionRequest $request, EmployeeChangeRequest $changeRequest): ChangeRequestResource
    {
        return $this->decide($request, $changeRequest, false);
    }

    private function decide(DecisionRequest $request, EmployeeChangeRequest $changeRequest, bool $approve): ChangeRequestResource
    {
        $actor = $this->actor($request);
        $ctx = $this->scope->for($actor);

        return ChangeRequestResource::for($this->service->decide($actor, $ctx, $changeRequest, $approve, $request->comment()), $ctx);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
