<?php

declare(strict_types=1);

namespace App\Modules\Overview\Services;

use App\Models\User;
use App\Modules\Core\Support\UserTime;
use App\Modules\Overview\Contracts\DashboardNotices;
use App\Modules\Overview\Contracts\DashboardRepository;
use App\Modules\Overview\Contracts\DashboardSection;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Recruiting\Services\StalenessService;
use App\Modules\Scripts\Contracts\TaskReader;
use App\Modules\Scripts\DTO\TaskFilter;
use App\Modules\Scripts\Enums\TaskDue;
use App\Modules\Scripts\Models\Task;
use Illuminate\Support\Carbon;

/**
 * The home page in one request: counters, my tasks for today (overdue included), the 10 most stale applications,
 * the funnel of active applications with its two captions (bottleneck, average path to the offer), today's route
 * (interviews + tasks) and touches of the last 7 days by channel — all within the user's scope.
 * Other modules add blocks through the DashboardSection tag (TimeOff: data.timeoff).
 */
final readonly class DashboardService
{
    public const int STALE_LIST = 10;

    public const int TASKS_LIST = 20;

    public const int TOUCH_DAYS = 7;

    public function __construct(
        private DashboardRepository $dashboard,
        private ApplicationRepository $applications,
        private RecruitingScope $scope,
        private TaskReader $tasks,
        private DayRouteService $dayRoute,
        private FunnelInsightsService $funnelInsights,
        /** @var iterable<DashboardNotices> */
        private iterable $notices = [],
        /** @var iterable<DashboardSection> */
        private iterable $sections = [],
    ) {}

    /** @return array<string, mixed> */
    public function build(User $actor, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        // "Today" (new today, my tasks, the day route) is the user's day, not the server's UTC day: the UI shows local time.
        $today = UserTime::now($now);
        $todayStart = UserTime::toStorage($today->copy()->startOfDay());
        $scope = $this->scope->for($actor);
        $staleBefore = $now->copy()->subDays(StalenessService::DEFAULT_DAYS);

        $tasks = $this->tasks->list($actor, new TaskFilter(mine: true, due: TaskDue::Today), $today);
        $stale = $this->applications->stale($scope, $staleBefore, self::STALE_LIST);

        $data = [
            'counts' => $this->dashboard->counts($scope, $staleBefore, $todayStart),
            'stale_days' => StalenessService::DEFAULT_DAYS,
            'my_tasks' => [
                'total' => $tasks->count(),
                'overdue' => $tasks->filter(static fn (Task $t): bool => $t->due_at->lt($todayStart))->count(),
                'items' => $tasks->take(self::TASKS_LIST)->map(static fn (Task $t): array => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'type' => $t->type->value,
                    'due_at' => $t->due_at->toIso8601String(),
                    'candidate' => $t->candidate === null ? null : ['id' => $t->candidate->id, 'name' => $t->candidate->full_name],
                ])->values()->all(),
            ],
            'stale' => $stale->map(static fn (Application $a): array => [
                'application_id' => $a->id,
                'candidate' => ['id' => $a->candidate->id, 'name' => $a->candidate->full_name],
                'vacancy' => ['id' => $a->vacancy->id, 'title' => $a->vacancy->title],
                'stage' => $a->stage->name,
                'last_activity_at' => $a->lastActivityAt()?->toIso8601String(),
                'days' => (int) floor(($a->lastActivityAt() ?? $now)->diffInDays($now)),
            ])->values()->all(),
            'warnings' => $this->warnings($actor),
            'funnel' => $this->dashboard->funnel($scope),
            'funnel_insights' => $this->funnelInsights->build($scope, $now),
            'day_route' => $this->dayRoute->build($scope, $tasks, $today),
            'touches' => [
                'days' => self::TOUCH_DAYS,
                'by_channel' => $this->dashboard->touchesByChannel($scope, $now->copy()->subDays(self::TOUCH_DAYS)),
            ],
        ];
        foreach ($this->sections as $section) {
            $data[$section->key()] = $section->data($actor, $now);
        }

        return $data;
    }

    /** @return list<array<string, mixed>> notices of other modules (DashboardNotices tag) */
    private function warnings(User $actor): array
    {
        $all = [];
        foreach ($this->notices as $source) {
            $all = [...$all, ...$source->for($actor)];
        }

        return $all;
    }
}
