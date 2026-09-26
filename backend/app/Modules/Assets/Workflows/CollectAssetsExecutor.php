<?php

declare(strict_types=1);

namespace App\Modules\Assets\Workflows;

use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Scripts\Services\TaskService;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;
use App\Modules\Workflows\Executors\TaskStepExecutor;
use App\Modules\Workflows\Services\AssigneeResolver;

/**
 * collect_assets (offboarding): a task for the assignee listing every asset the employee still holds
 * ("Зібрати активи: INV-001 Ноутбук, …"); link — the profile tab "Активи". The step waits for the task.
 * Nothing assigned → skipped "no_assets". Registered from the Assets module into the Workflows executor tag
 * (Open/Closed: Workflows does not know about assets).
 */
final class CollectAssetsExecutor extends TaskStepExecutor
{
    /** Task titles are at most 255 characters; the list is cut with "…". */
    private const int TITLE_MAX = 255;

    public function __construct(TaskService $tasks, AssigneeResolver $assignees, private readonly AssetService $assets)
    {
        parent::__construct($tasks, $assignees);
    }

    public function action(): StepAction
    {
        return StepAction::CollectAssets;
    }

    public function configRules(): array
    {
        return ['title' => ['nullable', 'string', 'max:120']];
    }

    public function execute(StepContext $context): StepOutcome
    {
        $held = $this->assets->heldBy($context->employee->id);
        if ($held->isEmpty()) {
            return StepOutcome::skipped('no_assets');
        }
        $list = $held->map(static fn (Asset $a): string => $a->inventory_number.' '.$a->name)->implode(', ');
        $title = ($context->step->string('title') ?? 'Зібрати активи').': '.$list;
        if (mb_strlen($title) > self::TITLE_MAX) {
            $title = mb_substr($title, 0, self::TITLE_MAX - 1).'…';
        }
        $outcome = $this->assignTask($context, $title, self::profileLink($context->employee->id, 'assets'));

        return $outcome->waiting ? StepOutcome::waiting($outcome->result + ['assets' => $held->count()]) : $outcome;
    }
}
