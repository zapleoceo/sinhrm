<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Controllers;

use App\Models\User;
use App\Modules\People\Http\Requests\DecisionRequest;
use App\Modules\People\Services\PeopleScope;
use App\Modules\TimeOff\Exceptions\TimeOffException;
use App\Modules\TimeOff\Http\Requests\CalendarRequest;
use App\Modules\TimeOff\Http\Requests\LeaveRequestFormRequest;
use App\Modules\TimeOff\Http\Requests\ListLeaveRequestsRequest;
use App\Modules\TimeOff\Http\Resources\LeaveRequestResource;
use App\Modules\TimeOff\Models\LeaveRequest;
use App\Modules\TimeOff\Services\CalendarService;
use App\Modules\TimeOff\Services\EmployeeResolver;
use App\Modules\TimeOff\Services\LeaveRequestService;
use App\Modules\TimeOff\Services\LeaveSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Leave requests: list in scope, preview, create (own; admin or a manager above may file for someone else),
 * approve / reject (admin or manager above), cancel, approvals inbox, team calendar.
 */
final class LeaveRequestController
{
    public function __construct(
        private readonly LeaveRequestService $service,
        private readonly PeopleScope $scope,
        private readonly EmployeeResolver $resolver,
        private readonly LeaveSettingsService $settings,
    ) {}

    public function index(ListLeaveRequestsRequest $request): AnonymousResourceCollection
    {
        $ctx = $this->scope->for($this->actor($request));

        return LeaveRequestResource::collection($this->service->list($ctx, $request->filter())->through(
            static fn (LeaveRequest $r): LeaveRequestResource => LeaveRequestResource::for($r, $ctx),
        ));
    }

    public function preview(LeaveRequestFormRequest $request): JsonResponse
    {
        $ctx = $this->scope->for($this->actor($request));
        $employee = $this->resolver->resolve($ctx, $request->employeeId());
        $data = $request->leaveData();

        return new JsonResponse(['data' => $this->service->preview(
            $employee,
            $this->settings->findType($data->leaveTypeId),
            $data->startsOn,
            $data->endsOn,
            $data->halfDay,
        )]);
    }

    public function store(LeaveRequestFormRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $ctx = $this->scope->for($actor);
        $employee = $this->resolver->resolve($ctx, $request->employeeId());
        // Filing for someone else: only an admin or a manager above that employee.
        if (! $ctx->isSelf($employee->id) && ! $ctx->canDecideFor($employee->id)) {
            throw TimeOffException::forbidden();
        }
        $data = $request->leaveData();
        $created = $this->service->create($actor, $ctx, $employee, $this->settings->findType($data->leaveTypeId), $data);

        return LeaveRequestResource::for($created, $ctx)->response()->setStatusCode(201);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $ctx = $this->scope->for($this->actor($request));
        if (! $ctx->canSeeJob($leaveRequest->employee_id)) {
            throw TimeOffException::forbidden();
        }

        return LeaveRequestResource::for($this->service->find($leaveRequest->id), $ctx);
    }

    public function approve(DecisionRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $actor = $this->actor($request);
        $ctx = $this->scope->for($actor);

        return LeaveRequestResource::for($this->service->approve($actor, $ctx, $this->service->find($leaveRequest->id), $request->comment()), $ctx);
    }

    public function reject(DecisionRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $actor = $this->actor($request);
        $ctx = $this->scope->for($actor);

        return LeaveRequestResource::for($this->service->reject($actor, $ctx, $leaveRequest, $request->comment()), $ctx);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $actor = $this->actor($request);
        $ctx = $this->scope->for($actor);

        return LeaveRequestResource::for($this->service->cancel($actor, $ctx, $this->service->find($leaveRequest->id), Carbon::today()), $ctx);
    }

    /** Pending requests the caller may decide (manager inbox; admins see all), oldest first. */
    public function approvals(Request $request): AnonymousResourceCollection
    {
        $ctx = $this->scope->for($this->actor($request));

        return LeaveRequestResource::collection($this->service->approvals($ctx)->map(
            static fn (LeaveRequest $r): LeaveRequestResource => LeaveRequestResource::for($r, $ctx),
        ));
    }

    public function calendar(CalendarRequest $request, CalendarService $calendar): JsonResponse
    {
        $ctx = $this->scope->for($this->actor($request));

        return new JsonResponse([
            'data' => $calendar->calendar($ctx, $request->from(), $request->to(), $request->branchId()),
            'meta' => ['from' => $request->from()->toDateString(), 'to' => $request->to()->toDateString()],
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
