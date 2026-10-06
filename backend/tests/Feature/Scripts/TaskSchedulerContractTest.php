<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use App\Modules\Scripts\Contracts\TaskScheduler;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskSource;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Models\Task;
use App\Modules\Scripts\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Scripts\Contracts\TaskScheduler: the boundary other modules (TimeOff handover) use to create and close tasks. */
final class TaskSchedulerContractTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_contract_resolves_to_task_service_schedules_once_and_closes(): void
    {
        $scheduler = $this->app->make(TaskScheduler::class);
        $this->assertInstanceOf(TaskService::class, $scheduler);
        $assignee = $this->login();
        $employee = $this->employee(['full_name' => 'Absent Person']);
        $task = new NewTask(
            assigneeId: $assignee->id,
            type: TaskType::LeaveHandover,
            title: 'Заміщення: Absent Person відсутній з 12.10.2026 по 16.10.2026',
            dueAt: Carbon::parse('2026-10-12'),
            ruleKey: 'timeoff:handover:1',
            employeeId: $employee->id,
        );

        $first = $scheduler->schedule($task);
        $this->assertSame($first->id, $scheduler->schedule($task)->id);
        $this->assertSame(1, Task::query()->count());

        $scheduler->closeByRule($employee->id, 'timeoff:handover:1');
        $this->assertNotNull($first->fresh()?->done_at);
    }

    public function test_leave_handover_tasks_are_in_the_timeoff_source(): void
    {
        $this->assertSame(TaskSource::TimeOff, TaskType::LeaveHandover->source());
        $this->assertSame(['leave_handover'], TaskSource::TimeOff->typeValues());
    }
}
