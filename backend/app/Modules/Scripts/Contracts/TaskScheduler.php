<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Contracts;

use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Models\Task;
use Illuminate\Support\Carbon;

/**
 * How another module creates and closes its tasks in "Мої задачі" without importing Scripts internals
 * (module boundaries). Implemented by Services\TaskService.
 */
interface TaskScheduler
{
    /** A task once per (employee, rule key); a repeat returns the stored task. */
    public function schedule(NewTask $task): Task;

    /** Closes the task of (employee, rule key), if any. */
    public function closeByRule(int $employeeId, string $ruleKey, ?Carbon $at = null): void;
}
