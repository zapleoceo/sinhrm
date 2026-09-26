<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Resources;

use App\Modules\People\DTO\PeopleContext;
use App\Modules\TimeOff\Enums\LeaveRequestStatus;
use App\Modules\TimeOff\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin LeaveRequest */
final class LeaveRequestResource extends JsonResource
{
    private ?PeopleContext $context = null;

    public static function for(LeaveRequest $request, PeopleContext $context): self
    {
        $resource = new self($request);
        $resource->context = $context;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ctx = $this->context;
        $open = in_array($this->status, [LeaveRequestStatus::Pending, LeaveRequestStatus::Approved], true);
        $decider = $ctx !== null && $ctx->canDecideFor($this->employee_id);
        $ownCancellable = $ctx !== null && $ctx->isSelf($this->employee_id)
            && ($this->status === LeaveRequestStatus::Pending || $this->starts_on->gt(Carbon::today()));

        return [
            'id' => $this->id,
            'employee' => ['id' => $this->employee->id, 'full_name' => $this->employee->full_name],
            'leave_type' => ['id' => $this->leaveType->id, 'name' => $this->leaveType->name, 'color' => $this->leaveType->color],
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'half_day' => $this->half_day->value,
            'days' => $this->daysValue(),
            'comment' => $this->comment,
            'status' => $this->status->value,
            'balance_override' => $this->balance_override,
            'approver' => $this->approver === null ? null : ['id' => $this->approver->id, 'name' => $this->approver->name],
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'can_decide' => $decider && $this->status === LeaveRequestStatus::Pending,
            'can_cancel' => $open && ($decider || $ownCancellable),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
