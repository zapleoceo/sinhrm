<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Models\User;
use App\Modules\People\Models\Employee;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskSource;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Events\TaskCompleted;
use App\Modules\Scripts\Models\Task;
use App\Modules\Scripts\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Atomic done transitions (event exactly once), idempotent schedule, source mapping. Synthetic data. */
final class TaskTransitionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_event_fires_once_even_when_a_stale_copy_completes_again(): void
    {
        Event::fake([TaskCompleted::class]);
        $user = User::factory()->create();
        $service = $this->app->make(TaskService::class);
        $new = new NewTask($user->id, TaskType::Workflow, 'Do it', Carbon::now(), 'wf:1', Employee::factory()->create()->id);
        $task = $service->schedule($new);
        $this->assertSame($task->id, $service->schedule($new)->id, 'schedule is idempotent');
        $stale = Task::query()->findOrFail($task->id);

        $service->complete($user, $task, true);
        $service->complete($user, $stale, true);

        Event::assertDispatchedTimes(TaskCompleted::class, 1);
        $this->assertNotNull($task->refresh()->done_at);
    }

    public function test_every_type_has_a_source(): void
    {
        $this->assertSame(['followup', 'manual', 'new_applicant'], TaskSource::Recruiting->typeValues());
        $this->assertSame(['workflow'], TaskSource::Workflows->typeValues());
        $this->assertSame(['document'], TaskSource::Documents->typeValues());
    }
}
