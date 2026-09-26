<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\TimeOff\Contracts\LeaveRequestRepository;
use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Enums\LeaveRequestStatus;
use App\Modules\TimeOff\Exceptions\TimeOffException;
use App\Modules\TimeOff\Models\Holiday;
use App\Modules\TimeOff\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * Who is absent: admin — everyone; others — themselves, everyone below them and their peers (same manager).
 * Only dates and the leave type are shown, never comments.
 */
final readonly class CalendarService
{
    public const int MAX_DAYS = 62;

    public function __construct(
        private LeaveRequestRepository $requests,
        private LeaveSettingsRepository $settings,
        private EmployeeRepository $employees,
    ) {}

    /** @return array{absences: list<array<string, mixed>>, holidays: list<array<string, mixed>>} */
    public function calendar(PeopleContext $ctx, Carbon $from, Carbon $to, ?int $branchId): array
    {
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw TimeOffException::rangeTooLong(self::MAX_DAYS);
        }
        $items = $this->requests->inRange(
            $this->visibleIds($ctx),
            $from,
            $to,
            [LeaveRequestStatus::Approved, LeaveRequestStatus::Pending],
            $branchId,
        );

        return [
            'absences' => array_values($items->map(fn (LeaveRequest $r): array => $this->absence($r))->all()),
            'holidays' => array_values($this->settings->holidaysBetween($from, $to, $branchId)
                ->map(static fn (Holiday $h): array => ['date' => $h->date->toDateString(), 'name' => $h->name, 'branch_id' => $h->branch_id])
                ->all()),
        ];
    }

    /** @return list<array<string, mixed>> approved absences covering the day */
    public function outOn(PeopleContext $ctx, Carbon $day): array
    {
        $items = $this->requests->inRange($this->visibleIds($ctx), $day, $day, [LeaveRequestStatus::Approved]);

        return array_values($items->map(fn (LeaveRequest $r): array => $this->absence($r))->all());
    }

    /** @return list<int>|null */
    public function visibleIds(PeopleContext $ctx): ?array
    {
        if ($ctx->admin) {
            return null;
        }
        if ($ctx->selfId === null) {
            return [];
        }
        $managerOf = $this->employees->managerMap();
        $manager = $managerOf[$ctx->selfId] ?? null;
        $peers = $manager === null ? [] : array_keys(array_filter($managerOf, static fn (?int $m): bool => $m === $manager));

        return array_values(array_unique([$ctx->selfId, ...$ctx->subtreeIds, ...$peers]));
    }

    /** @return array<string, mixed> */
    private function absence(LeaveRequest $r): array
    {
        return [
            'id' => $r->id,
            'employee' => ['id' => $r->employee->id, 'full_name' => $r->employee->full_name, 'avatar_url' => $r->employee->avatar_url],
            'leave_type' => ['id' => $r->leaveType->id, 'name' => $r->leaveType->name, 'color' => $r->leaveType->color],
            'starts_on' => $r->starts_on->toDateString(),
            'ends_on' => $r->ends_on->toDateString(),
            'half_day' => $r->half_day->value,
            'status' => $r->status->value,
        ];
    }
}
