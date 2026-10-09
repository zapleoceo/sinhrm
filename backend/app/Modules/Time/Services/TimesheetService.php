<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Models\User;
use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Core\Contracts\UserNotifier;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;
use App\Modules\Scripts\Contracts\TaskScheduler;
use App\Modules\Time\Contracts\TimeRepository;
use App\Modules\Time\Enums\TimesheetStatus;
use App\Modules\Time\Exceptions\TimeException;
use App\Modules\Time\Models\TimeEntry;
use App\Modules\Time\Models\Timesheet;
use App\Modules\Time\Support\WeekCalculator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Weekly timesheets. Access follows People (PeopleScope): the employee fills and submits their own week, a manager
 * sees and decides the weeks of their subtree (not their own), an admin sees and decides everything and may fill in
 * for anyone. Invisible → 404; visible but not allowed → 403.
 *
 * Audit (through the Audit contract, entity "timesheet"): submit, approve, reject, a save that changes the status
 * (rejected → draft) and every save of someone else's week (admin fill-in, meta.on_behalf). Hours, entries and the
 * decision comment are masked — the log keeps the fact and the field names. The employee's own drafting is not logged.
 */
final readonly class TimesheetService
{
    public const int LIMIT = 300;

    public const float MAX_DAY_HOURS = 24.0;

    public const string AUDIT_ENTITY = 'timesheet';

    /** Entry fields compared on save; a changed one is logged as `entries.<field>` (masked). */
    private const array ENTRY_FIELDS = ['date', 'hours', 'project', 'category', 'note'];

    public function __construct(
        private TimeRepository $time,
        private WeekSummaryService $summaries,
        private PeopleAccess $scope,
        private EmployeeRepository $employees,
        private TaskScheduler $tasks,
        private UserNotifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * The week grid of an employee (self when null).
     *
     * @return array<string, mixed>
     */
    public function week(User $user, ?int $employeeId, Carbon $date): array
    {
        $ctx = $this->scope->for($user);
        $employee = $this->visibleEmployee($user, $ctx, $employeeId);
        $start = WeekCalculator::weekStart($date);
        $sheet = $this->time->findWeek($employee->id, $start);

        return $this->view($ctx, $employee, $start, $sheet);
    }

    /**
     * Replaces the entries of the week (draft or rejected; saving a rejected week makes it a draft again).
     *
     * @param  list<array{date: string, hours: float, project: string|null, category: string|null, note: string|null}>  $entries
     * @return array<string, mixed>
     *
     * @throws TimeException
     */
    public function save(User $user, ?int $employeeId, Carbon $date, array $entries): array
    {
        $ctx = $this->scope->for($user);
        $employee = $this->visibleEmployee($user, $ctx, $employeeId);
        abort_unless($this->canEdit($ctx, $employee->id), 403);
        $start = WeekCalculator::weekStart($date);
        $this->assertEntries($start, $entries);
        $sheet = $this->time->firstOrCreateWeek($employee->id, $start);
        if (! $sheet->status->isEditable()) {
            throw TimeException::notEditable($sheet->status->value);
        }
        $before = $this->entryColumns($sheet);
        $statusBefore = $sheet->status;
        $this->time->transaction(function () use ($sheet, $entries): void {
            $this->time->replaceEntries($sheet, $entries);
            if ($sheet->status === TimesheetStatus::Rejected) {
                $this->time->transition($sheet, [TimesheetStatus::Rejected], ['status' => TimesheetStatus::Draft->value]);
            }
        });
        $sheet->setRelation('employee', $employee);
        $this->summaries->refreshTotals($sheet);
        $fresh = $this->time->findWeek($employee->id, $start);
        if ($fresh !== null) {
            $this->auditSave($ctx, $fresh, $before, $statusBefore);
        }

        return $this->view($ctx, $employee, $start, $fresh);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TimeException
     */
    public function submit(User $user, ?int $employeeId, Carbon $date, ?Carbon $now = null): array
    {
        $ctx = $this->scope->for($user);
        $employee = $this->visibleEmployee($user, $ctx, $employeeId);
        abort_unless($this->canEdit($ctx, $employee->id), 403);
        $start = WeekCalculator::weekStart($date);
        $sheet = $this->time->firstOrCreateWeek($employee->id, $start);
        $sheet->setRelation('employee', $employee);
        $this->summaries->refreshTotals($sheet);
        $before = $sheet->getAttributes();
        if (! $this->time->transition($sheet, [TimesheetStatus::Draft, TimesheetStatus::Rejected], [
            'status' => TimesheetStatus::Submitted->value, 'submitted_at' => $now ?? Carbon::now(),
            'decided_by' => null, 'decided_at' => null, 'decision_comment' => null,
        ])) {
            throw TimeException::invalidStatus($sheet->status->value);
        }
        $this->auditTransition($ctx, $sheet, $before);
        // The Friday reminder for this week is no longer needed.
        $this->tasks->closeByRule($employee->id, TimeReminderJob::ruleKey($start));
        $managerUser = $employee->manager?->user_id;
        if ($managerUser !== null && $managerUser !== $user->id) {
            $this->notifier->notify($managerUser, 'time', 'Погодити табель: '.$employee->full_name,
                sprintf('%s надіслав(ла) табель за тиждень з %s. Потрібне ваше рішення.', $employee->full_name, $start->format('d.m.Y')),
                '/time/approvals');
        }

        return $this->view($ctx, $employee, $start, $this->time->findWeek($employee->id, $start));
    }

    /**
     * Manager of the employee (subtree) or admin; never the employee themself unless admin.
     *
     * @return array<string, mixed>
     *
     * @throws TimeException
     */
    public function decide(User $user, int $timesheetId, bool $approve, ?string $comment, ?Carbon $now = null): array
    {
        $ctx = $this->scope->for($user);
        $sheet = $this->time->find($timesheetId);
        if ($sheet === null || ! $ctx->canSeeJob($sheet->employee_id)) {
            abort(404);
        }
        abort_unless($ctx->canDecideFor($sheet->employee_id), 403);
        $before = $sheet->getAttributes();
        if (! $this->time->transition($sheet, [TimesheetStatus::Submitted], [
            'status' => ($approve ? TimesheetStatus::Approved : TimesheetStatus::Rejected)->value,
            'decided_by' => $user->id,
            'decided_at' => $now ?? Carbon::now(),
            'decision_comment' => $comment,
        ])) {
            throw TimeException::invalidStatus($sheet->status->value);
        }
        $this->auditTransition($ctx, $sheet, $before);
        $employeeUser = $sheet->employee->user_id;
        if ($employeeUser !== null && $employeeUser !== $user->id) {
            $this->notifier->notify($employeeUser, 'time', $approve ? 'Табель погоджено' : 'Табель відхилено',
                sprintf('Табель за тиждень з %s %s.', $sheet->week_start->format('d.m.Y'), $approve ? 'погоджено' : 'відхилено')
                .($comment !== null ? "\nКоментар: ".$comment : ''),
                '/time');
        }

        return $this->view($ctx, $sheet->employee, $sheet->week_start, $this->time->find($timesheetId));
    }

    /** @return Collection<int, Timesheet> submitted weeks the user may decide (own excluded) */
    public function approvals(User $user): Collection
    {
        $ctx = $this->scope->for($user);
        if (! $ctx->admin && ! $ctx->isManager()) {
            return new Collection;
        }

        return $this->time->submitted($ctx->admin ? null : $ctx->subtreeIds, $ctx->selfId, self::LIMIT);
    }

    /** How many approvals() would list without the limit (sidebar counter). */
    public function approvalsCount(User $user): int
    {
        $ctx = $this->scope->for($user);
        if (! $ctx->admin && ! $ctx->isManager()) {
            return 0;
        }

        return $this->time->countSubmitted($ctx->admin ? null : $ctx->subtreeIds, $ctx->selfId);
    }

    /**
     * Team overview of a week: every visible working employee (subtree, or all for admins) with totals and status.
     *
     * @return list<array<string, mixed>>
     */
    public function team(User $user, Carbon $date, ?int $branchId): array
    {
        $ctx = $this->scope->for($user);
        $ids = $ctx->admin ? null : $ctx->subtreeIds;
        if ($ids === []) {
            return [];
        }
        $people = $this->employees->working($ids, $branchId);
        $start = WeekCalculator::weekStart($date);
        $sums = $this->summaries->summaries($people, $start, $start);
        $rows = [];
        foreach ($people as $e) {
            $s = $sums[$e->id][$start->toDateString()];
            $rows[] = [
                'employee' => ['id' => $e->id, 'full_name' => $e->full_name],
                'timesheet_id' => $s['timesheet_id'],
                'status' => $s['status'],
                'expected' => $s['expected'],
                'worked' => $s['worked'],
                'overtime' => $s['overtime'],
                'missing' => $s['missing'],
                'absence' => $s['absence'],
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function view(PeopleContext $ctx, Employee $employee, Carbon $start, ?Timesheet $sheet): array
    {
        $sum = $this->summaries->week($employee, $start);

        return [
            'employee' => ['id' => $employee->id, 'full_name' => $employee->full_name],
            'week_start' => $start->toDateString(),
            'timesheet_id' => $sheet?->id,
            'status' => $sheet?->status->value ?? TimesheetStatus::Draft->value,
            'schedule' => $sum['schedule'],
            'days' => $sum['days'],
            'totals' => ['expected' => $sum['expected'], 'worked' => $sum['worked'], 'overtime' => $sum['overtime'], 'missing' => $sum['missing'], 'absence' => $sum['absence']],
            'entries' => $sheet === null ? [] : $sheet->entries->map(static fn ($e): array => [
                'id' => $e->id, 'date' => $e->date->toDateString(), 'hours' => (float) $e->hours,
                'project' => $e->project, 'category' => $e->category, 'note' => $e->note,
            ])->values()->all(),
            'submitted_at' => $sheet?->submitted_at?->toIso8601String(),
            'decided_by' => $sheet?->decider === null ? null : ['id' => $sheet->decider->id, 'name' => $sheet->decider->name],
            'decided_at' => $sheet?->decided_at?->toIso8601String(),
            'decision_comment' => $sheet?->decision_comment,
            'can' => [
                'edit' => $this->canEdit($ctx, $employee->id) && ($sheet === null || $sheet->status->isEditable()),
                'decide' => $sheet !== null && $sheet->status === TimesheetStatus::Submitted && $ctx->canDecideFor($employee->id),
            ],
        ];
    }

    /** @param  array<string, mixed>  $before  raw attributes before the transition */
    private function auditTransition(PeopleContext $ctx, Timesheet $sheet, array $before): void
    {
        $this->audit->recordDiff(self::AUDIT_ENTITY, $sheet->id, AuditAction::StatusChanged, $before, $sheet->getAttributes(), $this->auditMeta($ctx, $sheet));
    }

    /**
     * A save is logged when it changes the status (rejected → draft) or edits someone else's week (admin fill-in).
     *
     * @param  array<string, list<mixed>>  $before  entryColumns() before the save
     */
    private function auditSave(PeopleContext $ctx, Timesheet $sheet, array $before, TimesheetStatus $statusBefore): void
    {
        $onBehalf = ! $ctx->isSelf($sheet->employee_id);
        if (! $onBehalf && $sheet->status === $statusBefore) {
            return;
        }
        $changes = [];
        if ($sheet->status !== $statusBefore) {
            $changes['status'] = ['from' => $statusBefore->value, 'to' => $sheet->status->value];
        }
        $after = $this->entryColumns($sheet);
        foreach (self::ENTRY_FIELDS as $field) {
            if ($before[$field] !== $after[$field]) {
                // Masked by Audit (not allow-listed): the row says which entry field changed, never the hours or the note.
                $changes['entries.'.$field] = ['from' => $before[$field] === [] ? null : $before[$field], 'to' => $after[$field] === [] ? null : $after[$field]];
            }
        }
        if ($changes === []) {
            return;
        }
        $action = isset($changes['status']) ? AuditAction::StatusChanged : AuditAction::Updated;
        $this->audit->record(self::AUDIT_ENTITY, $sheet->id, $action, $changes, $this->auditMeta($ctx, $sheet));
    }

    /**
     * The week's entries column by column, values as text (decimal "8.00" and float 8.0 compare equal).
     *
     * @return array<string, list<string|null>>
     */
    private function entryColumns(Timesheet $sheet): array
    {
        $rows = $sheet->entries->values(); // the relation is ordered by date, id
        $columns = [];
        foreach (self::ENTRY_FIELDS as $field) {
            $columns[$field] = $rows->map(static function (TimeEntry $e) use ($field): ?string {
                $value = $e->getAttribute($field);

                return match (true) {
                    $value === null => null,
                    $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                    $field === 'hours' => number_format((float) $value, 2, '.', ''),
                    default => (string) $value,
                };
            })->all();
        }

        return $columns;
    }

    /** @return array<string, scalar> ids and the week only */
    private function auditMeta(PeopleContext $ctx, Timesheet $sheet): array
    {
        return [
            'employee_id' => $sheet->employee_id,
            'week_start' => $sheet->week_start->toDateString(),
            'on_behalf' => ! $ctx->isSelf($sheet->employee_id),
        ];
    }

    private function canEdit(PeopleContext $ctx, int $employeeId): bool
    {
        return $ctx->admin || $ctx->isSelf($employeeId);
    }

    /** @throws TimeException */
    private function visibleEmployee(User $user, PeopleContext $ctx, ?int $employeeId): Employee
    {
        if ($employeeId === null) {
            return $this->scope->employeeOf($user) ?? throw TimeException::noEmployee();
        }
        $employee = $this->employees->find($employeeId);
        if ($employee === null || ! $ctx->canSeeJob($employee->id)) {
            abort(404);
        }

        return $employee;
    }

    /**
     * @param  list<array{date: string, hours: float, project: string|null, category: string|null, note: string|null}>  $entries
     *
     * @throws TimeException
     */
    private function assertEntries(Carbon $start, array $entries): void
    {
        $end = $start->copy()->addDays(6)->toDateString();
        $perDay = [];
        foreach ($entries as $e) {
            if ($e['date'] < $start->toDateString() || $e['date'] > $end) {
                throw TimeException::outsideWeek($e['date']);
            }
            $perDay[$e['date']] = ($perDay[$e['date']] ?? 0.0) + $e['hours'];
            if ($perDay[$e['date']] > self::MAX_DAY_HOURS) {
                throw TimeException::dayOverflow($e['date']);
            }
        }
    }
}
