<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\People\Support\ReportingTree;
use App\Modules\Pulse\Contracts\MoodRepository;
use App\Modules\Pulse\Support\MoodStats;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Services\TaskService;
use Illuminate\Support\Carbon;

/**
 * Manager alerts: for every manager with a login, the team (their whole working subtree) average of the last
 * completed week (Mon–Sun) is compared with the week before. When both windows have at least the minimum group of people and the average
 * dropped by the configured threshold or more, the manager gets a task "Team mood dropped" (no names, no scores of
 * individuals). One task per manager per week ("mood:<ISO year>-W<week of the compared week>", the tasks idempotency key).
 */
final readonly class MoodAlerts
{
    public const string RULE_PREFIX = 'mood:';

    public function __construct(
        private MoodRepository $mood,
        private EmployeeRepository $employees,
        private TaskService $tasks,
    ) {}

    /** @return int teams whose drop crossed the threshold (the task itself is created once per week) */
    public function run(Carbon $now): int
    {
        $settings = $this->mood->settings();
        $minGroup = max(1, $settings->min_group);
        $threshold = (float) $settings->alert_drop;
        $managerOf = $this->employees->managerMap();
        $working = $this->employees->working()->keyBy('id');
        $today = $now->copy()->startOfDay();
        // The last completed ISO week against the one before (never the running week).
        $currentFrom = $today->copy()->startOfWeek()->subWeek();
        $previousFrom = $currentFrom->copy()->subWeek();
        $checkins = $this->mood->between(null, $previousFrom, $today->copy()->startOfWeek()->subDay());
        $ruleKey = self::RULE_PREFIX.$currentFrom->isoFormat('GGGG-[W]WW');

        $created = 0;
        foreach (array_unique(array_filter(array_values($managerOf))) as $managerId) {
            $manager = $working->get($managerId);
            if (! $manager instanceof Employee || $manager->user_id === null) {
                continue;
            }
            $team = array_flip(array_filter(ReportingTree::descendants($managerOf, (int) $managerId), static fn (int $id): bool => $working->has($id)));
            if (count($team) < $minGroup) {
                continue;
            }
            $current = $previous = [];
            foreach ($checkins as $c) {
                if (isset($team[$c['employee_id']])) {
                    if ($c['day'] >= $currentFrom->toDateString()) {
                        $current[] = $c;
                    } else {
                        $previous[] = $c;
                    }
                }
            }
            $now7 = MoodStats::bucket($current, $minGroup);
            $before = MoodStats::bucket($previous, $minGroup);
            if ($now7['average'] === null || $before['average'] === null || $threshold > $before['average'] - $now7['average']) {
                continue;
            }
            $this->tasks->schedule(new NewTask(
                assigneeId: $manager->user_id,
                type: TaskType::MoodAlert,
                title: sprintf('Настрій команди знизився: %.1f → %.1f', $before['average'], $now7['average']),
                dueAt: $today->copy()->addDays(2)->setTime(18, 0),
                ruleKey: $ruleKey,
                employeeId: $manager->id,
                link: '/pulse/mood',
            ));
            $created++;
        }

        return $created;
    }
}
