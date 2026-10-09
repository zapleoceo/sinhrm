<?php

declare(strict_types=1);

namespace Tests\Unit\HiringRequests;

use App\Modules\HiringRequests\Models\HiringApproval;
use App\Modules\HiringRequests\Services\ApproverNotifier;
use App\Modules\Scripts\Contracts\TaskScheduler;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\TestCase;

/** Approver tasks are closed through Scripts' TaskScheduler contract, not TaskService (no DB). */
final class ApproverTaskSchedulerTest extends TestCase
{
    public function test_closing_a_step_closes_its_approval_and_sla_tasks_by_prefix(): void
    {
        $now = Carbon::parse('2026-10-08 12:00:00');
        /** @var TaskScheduler&MockInterface $tasks */
        $tasks = $this->mock(TaskScheduler::class);
        $tasks->expects('closeByRulePrefix')->with('hrq:7:', $now)->andReturn(2);
        $tasks->expects('closeByRulePrefix')->with('hrq-sla:7:', $now)->andReturn(1);
        $step = new HiringApproval;
        $step->id = 7;

        $this->app->make(ApproverNotifier::class)->closeStep($step, $now);
    }
}
