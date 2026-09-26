<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Support;

use App\Modules\Workflows\Contracts\StepExecutor;
use App\Modules\Workflows\Enums\StepAction;
use LogicException;

/** The executors tagged in WorkflowsServiceProvider, by action. Every StepAction must have exactly one. */
final class ExecutorRegistry
{
    /** @var array<string, StepExecutor> */
    private array $byAction = [];

    /** @param  iterable<StepExecutor>  $executors */
    public function __construct(iterable $executors)
    {
        foreach ($executors as $executor) {
            $this->byAction[$executor->action()->value] = $executor;
        }
    }

    public function for(StepAction $action): StepExecutor
    {
        return $this->byAction[$action->value] ?? throw new LogicException('No executor for workflow action '.$action->value);
    }

    /** @return list<StepAction> actions that have an executor */
    public function actions(): array
    {
        return array_values(array_filter(StepAction::cases(), fn (StepAction $a): bool => isset($this->byAction[$a->value])));
    }
}
