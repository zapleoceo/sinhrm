<?php

declare(strict_types=1);

namespace App\Modules\Overview\Services;

use App\Modules\Overview\Contracts\DashboardRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Scripts\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Маршрут дня": today's interviews and tasks of the user on one time line.
 * - interviews: meetings scheduled from the candidate card (touchpoints "meeting", meta.start today) that the user
 *   scheduled or is an interviewer of, only for candidates the user may see;
 * - tasks: the user's own open tasks due today (overdue ones are not on today's line, they stay in "Мої задачі").
 * "Today" is the user's day [00:00, 23:59:59] in the user's time zone (Core UserTime, config app.user_timezone), the
 * same day as "Мої задачі на сьогодні"; every time in the answer carries that zone's offset, and `timezone` names it,
 * so the UI draws the line in the zone the day was cut in.
 */
final readonly class DayRouteService
{
    public function __construct(
        private DashboardRepository $dashboard,
        private CandidateRepository $candidates,
    ) {}

    /**
     * @param  Collection<int, Task>  $myTasks  my open tasks due by the end of today
     * @param  Carbon  $now  the current moment in the user's time zone (its day is "today")
     * @return array{date: string, timezone: string, interviews: int, tasks: int, items: list<array<string, mixed>>}
     */
    public function build(Scope $scope, Collection $myTasks, Carbon $now): array
    {
        $from = $now->copy()->startOfDay();
        $to = $now->copy()->endOfDay();

        $tz = $now->getTimezone();
        $items = [];
        $interviews = 0;
        foreach ($this->dashboard->meetingsInvolving($scope->userId, $scope->interviewApplicationIds, $from, $to) as $m) {
            if (! $this->candidates->isVisible($scope, $m['candidate_id'])) {
                continue;
            }
            $interviews++;
            $items[] = [
                'kind' => 'interview',
                'id' => $m['id'],
                'at' => $m['start']->copy()->setTimezone($tz)->toIso8601String(),
                'end' => $m['end']?->copy()->setTimezone($tz)->toIso8601String(),
                'title' => $m['title'],
                'meeting_type' => $m['meeting_type'],
                'candidate' => ['id' => $m['candidate_id'], 'name' => $m['candidate_name']],
            ];
        }

        $tasks = 0;
        foreach ($myTasks as $t) {
            if ($t->due_at->lt($from) || $t->due_at->gt($to)) {
                continue;
            }
            $tasks++;
            $items[] = [
                'kind' => 'task',
                'id' => $t->id,
                'at' => $t->due_at->copy()->setTimezone($tz)->toIso8601String(),
                'end' => null,
                'title' => $t->title,
                'type' => $t->type->value,
                'candidate' => $t->candidate === null ? null : ['id' => $t->candidate->id, 'name' => $t->candidate->full_name],
            ];
        }
        // Same time zone everywhere, so ISO strings sort chronologically; interviews before tasks at the same minute.
        usort($items, static fn (array $a, array $b): int => [$a['at'], $a['kind'], $a['id']] <=> [$b['at'], $b['kind'], $b['id']]);

        return ['date' => $from->toDateString(), 'timezone' => $tz->getName(), 'interviews' => $interviews, 'tasks' => $tasks, 'items' => $items];
    }
}
