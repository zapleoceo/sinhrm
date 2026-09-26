<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Models\User;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Contracts\LeaveRequestRepository;
use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Contracts\LedgerRepository;
use App\Modules\TimeOff\DTO\LeaveRequestData;
use App\Modules\TimeOff\DTO\LeaveRequestFilter;
use App\Modules\TimeOff\Enums\HalfDay;
use App\Modules\TimeOff\Enums\LeaveRequestStatus;
use App\Modules\TimeOff\Enums\LedgerReason;
use App\Modules\TimeOff\Exceptions\TimeOffException;
use App\Modules\TimeOff\Models\Holiday;
use App\Modules\TimeOff\Models\LeaveRequest;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Support\WorkingDayCalculator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Leave requests: day count (Mon–Fri minus holidays, half days), no overlaps, balance check for tracked types,
 * approval writes the ledger (−days), cancelling an approved request writes it back (+days).
 */
final readonly class LeaveRequestService
{
    public function __construct(
        private LeaveRequestRepository $requests,
        private LeaveSettingsRepository $settings,
        private LedgerRepository $ledger,
        private BalanceService $balances,
        private LoggerInterface $log,
    ) {}

    /** @return LengthAwarePaginator<int, LeaveRequest> */
    public function list(PeopleContext $ctx, LeaveRequestFilter $filter): LengthAwarePaginator
    {
        return $this->requests->paginate($ctx->visibleIds(), $filter);
    }

    /** @throws ModelNotFoundException<LeaveRequest> */
    public function find(int $id): LeaveRequest
    {
        return $this->requests->find($id) ?? throw (new ModelNotFoundException)->setModel(LeaveRequest::class, [$id]);
    }

    /**
     * What a request would cost, without saving (the form preview).
     *
     * @return array<string, mixed>
     */
    public function preview(Employee $employee, LeaveType $type, Carbon $from, Carbon $to, HalfDay $halfDay): array
    {
        $this->assertSpan($from, $to);
        $holidays = $this->settings->holidaysBetween($from, $to, $employee->branch_id)
            ->map(static fn (Holiday $h): array => ['date' => $h->date->toDateString(), 'name' => $h->name])
            ->values()
            ->all();
        $days = $this->days($employee, $from, $to, $halfDay);
        $available = $type->tracks_balance ? $this->balances->available($employee, $type) : null;

        return [
            'days' => $days,
            'holidays' => $holidays,
            'tracked' => $type->tracks_balance,
            'available' => $available,
            'sufficient' => $available === null || $available >= $days,
            'overlap' => $this->requests->overlapping($employee->id, $from, $to),
        ];
    }

    /**
     * @throws TimeOffException overlap | no_working_days | insufficient_balance | inactive_type | range_too_long
     */
    public function create(User $actor, PeopleContext $ctx, Employee $employee, LeaveType $type, LeaveRequestData $data): LeaveRequest
    {
        if (! $type->active) {
            throw TimeOffException::inactiveType();
        }
        $this->assertSpan($data->startsOn, $data->endsOn);
        $days = $this->days($employee, $data->startsOn, $data->endsOn, $data->halfDay);
        if ($days <= 0) {
            throw TimeOffException::noWorkingDays();
        }
        // Only an admin may push a balance below zero.
        $override = $data->overrideBalance && $ctx->admin;

        $request = $this->requests->transaction(function () use ($actor, $employee, $type, $data, $days, $override): LeaveRequest {
            if ($this->requests->overlapping($employee->id, $data->startsOn, $data->endsOn)) {
                throw TimeOffException::overlap();
            }
            $this->assertBalance($employee, $type, $days, $override, null);
            $request = $this->requests->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'starts_on' => $data->startsOn->toDateString(),
                'ends_on' => $data->endsOn->toDateString(),
                'half_day' => $data->halfDay->value,
                'days' => $days,
                'comment' => $data->comment,
                'status' => LeaveRequestStatus::Pending->value,
                'balance_override' => $override,
                'created_by' => $actor->id,
            ]);
            if (! $type->requires_approval) {
                $this->applyApproval($request, $type, null, null);
            }

            return $request;
        });
        $this->log->info('timeoff.request_created', ['id' => $request->id, 'employee' => $employee->id, 'by' => $actor->id]);

        return $this->find($request->id);
    }

    /** @throws TimeOffException forbidden | invalid_status | insufficient_balance */
    public function approve(User $actor, PeopleContext $ctx, LeaveRequest $request, ?string $comment): LeaveRequest
    {
        $this->assertCanDecide($ctx, $request);
        $this->requests->transaction(function () use ($actor, $request, $comment): void {
            if ($request->status !== LeaveRequestStatus::Pending) {
                throw TimeOffException::invalidStatus();
            }
            $this->assertBalance($request->employee, $request->leaveType, $request->daysValue(), $request->balance_override, $request->id);
            $this->applyApproval($request, $request->leaveType, $actor, $comment);
        });
        $this->log->info('timeoff.request_approved', ['id' => $request->id, 'by' => $actor->id]);

        return $this->find($request->id);
    }

    /** @throws TimeOffException forbidden | invalid_status */
    public function reject(User $actor, PeopleContext $ctx, LeaveRequest $request, ?string $comment): LeaveRequest
    {
        $this->assertCanDecide($ctx, $request);
        $done = $this->requests->transition($request, LeaveRequestStatus::Pending, [
            'status' => LeaveRequestStatus::Rejected->value,
            'approver_id' => $actor->id,
            'decided_at' => Carbon::now(),
            'decision_comment' => $comment,
        ]);
        if (! $done) {
            throw TimeOffException::invalidStatus();
        }
        $this->log->info('timeoff.request_rejected', ['id' => $request->id, 'by' => $actor->id]);

        return $this->find($request->id);
    }

    /**
     * The employee cancels a pending request or an approved one that has not started; an admin or a manager above
     * cancels any pending/approved request. An approved request gives its days back to the ledger.
     *
     * @throws TimeOffException forbidden | invalid_status
     */
    public function cancel(User $actor, PeopleContext $ctx, LeaveRequest $request, Carbon $today): LeaveRequest
    {
        $status = $request->status;
        if (! in_array($status, [LeaveRequestStatus::Pending, LeaveRequestStatus::Approved], true)) {
            throw TimeOffException::invalidStatus();
        }
        $decider = $ctx->canDecideFor($request->employee_id);
        $ownFuture = $ctx->isSelf($request->employee_id)
            && ($status === LeaveRequestStatus::Pending || $request->starts_on->gt($today));
        if (! $decider && ! $ownFuture) {
            throw TimeOffException::forbidden();
        }
        $this->requests->transaction(function () use ($actor, $request, $status): void {
            if (! $this->requests->transition($request, $status, ['status' => LeaveRequestStatus::Cancelled->value])) {
                throw TimeOffException::invalidStatus();
            }
            if ($status === LeaveRequestStatus::Approved && $request->leaveType->tracks_balance) {
                $this->ledger->append([
                    'employee_id' => $request->employee_id,
                    'leave_type_id' => $request->leave_type_id,
                    'delta' => $request->daysValue(),
                    'reason' => LedgerReason::Request->value,
                    'reference_id' => $request->id,
                    'comment' => 'cancelled',
                    'created_by' => $actor->id,
                ]);
            }
        });
        $this->log->info('timeoff.request_cancelled', ['id' => $request->id, 'by' => $actor->id]);

        return $this->find($request->id);
    }

    /**
     * Pending requests the user may decide (admin: all; manager: everyone below), own excluded.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function approvals(PeopleContext $ctx, int $limit = 200): Collection
    {
        if (! $ctx->admin && ! $ctx->isManager()) {
            return new Collection;
        }

        return $this->requests->pendingFor($ctx->admin ? null : $ctx->subtreeIds, $ctx->admin ? null : $ctx->selfId, $limit);
    }

    public function days(Employee $employee, Carbon $from, Carbon $to, HalfDay $halfDay): float
    {
        return WorkingDayCalculator::days($from, $to, $halfDay, $this->settings->holidayDates($from, $to, $employee->branch_id));
    }

    private function applyApproval(LeaveRequest $request, LeaveType $type, ?User $actor, ?string $comment): void
    {
        if (! $this->requests->transition($request, LeaveRequestStatus::Pending, [
            'status' => LeaveRequestStatus::Approved->value,
            'approver_id' => $actor?->id,
            'decided_at' => Carbon::now(),
            'decision_comment' => $comment,
        ])) {
            throw TimeOffException::invalidStatus();
        }
        if ($type->tracks_balance) {
            $this->ledger->append([
                'employee_id' => $request->employee_id,
                'leave_type_id' => $type->id,
                'delta' => -$request->daysValue(),
                'reason' => LedgerReason::Request->value,
                'reference_id' => $request->id,
                'created_by' => $actor?->id,
            ]);
        }
    }

    private function assertBalance(Employee $employee, LeaveType $type, float $days, bool $override, ?int $exceptRequestId): void
    {
        if (! $type->tracks_balance || $override) {
            return;
        }
        $available = $this->balances->available($employee, $type, $exceptRequestId);
        if ($available < $days) {
            throw TimeOffException::insufficientBalance($available, $days);
        }
    }

    private function assertCanDecide(PeopleContext $ctx, LeaveRequest $request): void
    {
        if (! $ctx->canDecideFor($request->employee_id)) {
            throw TimeOffException::forbidden();
        }
    }

    private function assertSpan(Carbon $from, Carbon $to): void
    {
        if ($from->diffInDays($to) > WorkingDayCalculator::MAX_SPAN_DAYS) {
            throw TimeOffException::rangeTooLong(WorkingDayCalculator::MAX_SPAN_DAYS);
        }
    }
}
