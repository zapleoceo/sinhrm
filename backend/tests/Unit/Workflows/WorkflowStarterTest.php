<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows;

use App\Modules\People\Models\Employee;
use App\Modules\Workflows\Models\WorkflowRunStep;
use App\Modules\Workflows\Services\WorkflowStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\WorkflowFixtures;
use Tests\TestCase;

final class WorkflowStarterTest extends TestCase
{
    use RefreshDatabase, WorkflowFixtures;

    public function test_anchor_is_a_date_and_input_is_unchanged_with_utc_user_timezone(): void
    {
        config(['app.timezone' => 'UTC', 'app.user_timezone' => 'UTC']);
        $anchor = Carbon::parse('2026-03-29 12:30:00', 'UTC');
        $template = $this->workflow([['create_task', -1], ['create_task', 0], ['create_task', 1]]);
        $run = $this->app->make(WorkflowStarter::class)->start($template, Employee::factory()->create(), $anchor);
        $this->assertNotNull($run);
        $this->assertSame('2026-03-29', $run->anchor_date->toDateString());
        $this->assertSame('2026-03-29 12:30:00', $anchor->format('Y-m-d H:i:s'));
        $this->assertSame(
            ['2026-03-28 00:00:00', '2026-03-29 00:00:00', '2026-03-30 00:00:00'],
            $run->steps->map(fn (WorkflowRunStep $step): string => $step->due_at->format('Y-m-d H:i:s'))->all(),
        );
    }
}
