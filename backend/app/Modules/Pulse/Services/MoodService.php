<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Models\User;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\People\Models\Employee;
use App\Modules\Pulse\Contracts\MoodRepository;
use App\Modules\Pulse\Exceptions\PulseException;
use App\Modules\Pulse\Models\MoodCheckin;
use App\Modules\Pulse\Models\MoodSetting;
use App\Modules\Pulse\Support\MoodStats;
use App\Modules\Pulse\Support\SafeSegments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Mood monitoring. An employee answers "how is your mood today" (1–5 + optional comment) once a day on the
 * configured weekdays (a second answer the same day replaces the first). Personal history — to the employee only.
 * Team trend — managers (their whole subtree, no branch/department filters: a slice of a team is a differencing
 * attack) and admins (everyone, or a branch / department whose remainder is still safe): aggregates of completed
 * weeks only (never the running week), suppressed below the minimum group; coverage is of the last completed week; comments without names or dates, only from shown weeks.
 */
final readonly class MoodService
{
    public const int MAX_WEEKS = 26;

    public const int MAX_COMMENTS = 50;

    public function __construct(
        private MoodRepository $mood,
        private PeopleAccess $scope,
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
        $minGroup = max(1, $this->mood->settings()->min_group);
        $ids = $this->teamIds($user, $branchId, $departmentId, $minGroup);
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
     * Admin: every working employee (optionally one branch / department); manager: their whole working subtree, never
     * a slice of it — "the team" minus "one department" is the aggregate of the few people left over, and with a
     * comment it is a name. Filters are therefore refused for managers (403).
     *
     * For an admin a slice is allowed only while the rest of the scope stays safe (SafeSegments): the slice itself at
     * least the minimum group and the remainder (everybody else) 0 or at least the minimum. Otherwise nobody is
     * returned and the whole report is suppressed.
     *
     * @return list<int>
     *
     * @throws AuthorizationException
     */
    private function teamIds(User $user, ?int $branchId, ?int $departmentId, int $minGroup): array
    {
        $ctx = $this->scope->for($user);
        if (! $ctx->admin && ! $ctx->isManager()) {
            throw new AuthorizationException;
        }
        $filtered = $branchId !== null || $departmentId !== null;
        if ($filtered && ! $ctx->admin) {
            throw new AuthorizationException;
        }
        $scopeIds = $ctx->admin ? null : $ctx->subtreeIds;
        $all = self::idsOf($this->employees->working($scopeIds));
        if (! $filtered) {
            return $all;
        }
        $slice = self::idsOf($this->employees->working($scopeIds, $branchId)
            ->filter(static fn (Employee $e): bool => $departmentId === null || $e->department_id === $departmentId));

        return SafeSegments::allowed([$slice], $minGroup, count($all))[0] ?? [];
    }

    /**
     * @param  Collection<int, Employee>  $people
     * @return list<int>
     */
    private static function idsOf(Collection $people): array
    {
        return $people->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }
}
