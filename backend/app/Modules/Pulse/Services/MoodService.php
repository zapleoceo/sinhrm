<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Models\User;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Pulse\Contracts\MoodRepository;
use App\Modules\Pulse\Exceptions\PulseException;
use App\Modules\Pulse\Models\MoodCheckin;
use App\Modules\Pulse\Models\MoodSetting;
use App\Modules\Pulse\Support\MoodStats;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Mood monitoring. An employee answers "how is your mood today" (1–5 + optional comment) once a day on the
 * configured weekdays (a second answer the same day replaces the first). Personal history — to the employee only.
 * Team trend — managers (their subtree) and admins (everyone, or a branch / department): aggregates of completed
 * weeks only (never the running week), suppressed below the minimum group; coverage is of the last completed week; comments without names or dates, only from shown weeks.
 */
final readonly class MoodService
{
    public const int MAX_WEEKS = 26;

    public const int MAX_COMMENTS = 50;

    public function __construct(
        private MoodRepository $mood,
        private PeopleScope $scope,
        private EmployeeRepository $employees,
    ) {}

    public function settings(): MoodSetting
    {
        return $this->mood->settings();
    }

    /** @param  array{weekdays: list<int>, question: string, required: bool, alert_drop: float, min_group: int}  $data */
    public function saveSettings(array $data): MoodSetting
    {
        return $this->mood->saveSettings($data);
    }

    /** @return array{ask: bool, question: string, required: bool, today: MoodCheckin|null, has_employee: bool} */
    public function today(User $user, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $settings = $this->mood->settings();
        $employee = $this->scope->employeeOf($user);
        $today = $employee === null ? null : $this->mood->forDay($employee->id, $now);

        return [
            'ask' => $employee !== null && $today === null && in_array($now->isoWeekday(), $settings->weekdays, true),
            'question' => $settings->question,
            'required' => $settings->required,
            'today' => $today,
            'has_employee' => $employee !== null,
        ];
    }

    /** @throws PulseException no_employee */
    public function checkIn(User $user, int $score, ?string $comment, ?Carbon $now = null): MoodCheckin
    {
        $employee = $this->scope->employeeOf($user) ?? throw PulseException::noEmployee();
        $comment = $comment === null ? null : (trim($comment) === '' ? null : mb_substr(trim($comment), 0, 1000));

        return $this->mood->upsert($employee->id, ($now ?? Carbon::now())->copy()->startOfDay(), $score, $comment);
    }

    /** @return Collection<int, MoodCheckin> */
    public function history(User $user, int $days, ?Carbon $now = null): Collection
    {
        $employee = $this->scope->employeeOf($user);
        $now ??= Carbon::now();

        return $employee === null ? new Collection : $this->mood->history($employee->id, $now->copy()->subDays($days - 1), $now);
    }

    /**
     * @return array{team_size: int|null, min_group: int, coverage: array{answered: int, total: int}|null, weeks: list<array<string, mixed>>, comments: list<string>}
     *
     * @throws AuthorizationException
     */
    public function team(User $user, int $weeks, ?int $branchId, ?int $departmentId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $weeks = max(1, min(self::MAX_WEEKS, $weeks));
        $ids = $this->teamIds($user, $branchId, $departmentId);
        $minGroup = max(1, $this->mood->settings()->min_group);
        // Completed weeks only: the running week would change with every new check-in (diffing reveals it).
        $currentWeek = $now->copy()->startOfWeek();
        $from = $currentWeek->copy()->subWeeks($weeks);
        $checkins = $this->mood->between($ids, $from, $currentWeek->copy()->subDay());

        $buckets = [];
        foreach ($checkins as $c) {
            $buckets[Carbon::parse($c['day'])->startOfWeek()->toDateString()][] = $c;
        }
        $out = [];
        $comments = [];
        for ($week = $from->copy(); $week->lt($currentWeek); $week->addWeek()) {
            $rows = $buckets[$week->toDateString()] ?? [];
            $stats = MoodStats::bucket($rows, $minGroup);
            $out[] = ['week_start' => $week->toDateString()] + $stats;
            if (! $stats['suppressed']) {
                foreach ($rows as $row) {
                    if ($row['comment'] !== null && $row['comment'] !== '') {
                        $comments[] = $row['comment'];
                    }
                }
            }
        }
        sort($comments, SORT_STRING);
        $teamSize = count($ids);
        $lastWeek = array_unique(array_column($buckets[$currentWeek->copy()->subWeek()->toDateString()] ?? [], 'employee_id'));

        return [
            'team_size' => $teamSize >= $minGroup ? $teamSize : null,
            'min_group' => $minGroup,
            'coverage' => $teamSize >= $minGroup ? ['answered' => count($lastWeek), 'total' => $teamSize] : null,
            'weeks' => $out,
            'comments' => array_slice($comments, 0, self::MAX_COMMENTS),
        ];
    }

    /**
     * Admin: every working employee (optionally one branch / department); manager: their working subtree.
     *
     * @return list<int>
     *
     * @throws AuthorizationException
     */
    private function teamIds(User $user, ?int $branchId, ?int $departmentId): array
    {
        $ctx = $this->scope->for($user);
        if (! $ctx->admin && ! $ctx->isManager()) {
            throw new AuthorizationException;
        }
        $people = $this->employees->working($ctx->admin ? null : $ctx->subtreeIds, $branchId);

        return $people->filter(static fn (Employee $e): bool => $departmentId === null || $e->department_id === $departmentId)
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }
}
