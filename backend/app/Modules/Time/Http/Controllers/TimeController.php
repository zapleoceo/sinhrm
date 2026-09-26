<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Models\User;
use App\Modules\Time\Http\Requests\SaveEntriesRequest;
use App\Modules\Time\Http\Requests\SaveScheduleRequest;
use App\Modules\Time\Http\Requests\WeekRequest;
use App\Modules\Time\Models\Timesheet;
use App\Modules\Time\Models\WorkSchedule;
use App\Modules\Time\Services\ScheduleService;
use App\Modules\Time\Services\TimesheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Time: my/any week grid, save entries, submit, manager decisions, team overview, work schedules (admins). */
final class TimeController
{
    public function __construct(private readonly TimesheetService $timesheets) {}

    public function week(WeekRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->timesheets->week($this->actor($request), $request->employeeId(), $request->week())]);
    }

    public function save(SaveEntriesRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->timesheets->save($this->actor($request), $request->employeeId(), $request->week(), $request->entries())]);
    }

    public function submit(WeekRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->timesheets->submit($this->actor($request), $request->employeeId(), $request->week())]);
    }

    public function decide(Request $request, int $timesheet): JsonResponse
    {
        $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'comment' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000'],
        ]);
        $comment = $request->filled('comment') ? trim($request->string('comment')->toString()) : null;

        return new JsonResponse(['data' => $this->timesheets->decide($this->actor($request), $timesheet, $request->string('decision')->toString() === 'approve', $comment)]);
    }

    public function approvals(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->timesheets->approvals($this->actor($request))->map(static fn (Timesheet $t): array => [
            'id' => $t->id,
            'employee' => ['id' => $t->employee->id, 'full_name' => $t->employee->full_name],
            'week_start' => $t->week_start->toDateString(),
            'status' => $t->status->value,
            'expected' => (float) $t->expected_hours,
            'worked' => (float) $t->worked_hours,
            'overtime' => (float) $t->overtime_hours,
            'submitted_at' => $t->submitted_at?->toIso8601String(),
            'entries' => $t->entries->count(),
        ])->values()->all()]);
    }

    public function team(WeekRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->timesheets->team($this->actor($request), $request->week(), $request->branchId())]);
    }

    public function schedules(ScheduleService $schedules): JsonResponse
    {
        return new JsonResponse(['data' => $schedules->list()->map(static fn (WorkSchedule $s): array => ScheduleService::present($s))->values()->all()]);
    }

    public function saveSchedule(SaveScheduleRequest $request, ScheduleService $schedules): JsonResponse
    {
        return new JsonResponse(['data' => ScheduleService::present($schedules->save($request->branchId(), $request->days(), $request->hoursPerDay()))]);
    }

    public function deleteSchedule(int $branch, ScheduleService $schedules): Response
    {
        $schedules->delete($branch);

        return new Response(null, 204);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
