<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\ScheduledTerminationJob;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Handover task on termination (owner decision 2026-10-07, A; Listeners/HandoverOnTermination): "Прийняти справи"
 * when the termination applies (once per date), closed on cancel / restore, skipped without a usable colleague.
 * Summer, Kyiv = UTC+3: now is 12:00 Kyiv on 2026-07-14.
 */
final class TerminationHandoverTaskTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    /** @var array{head: Employee, lead: Employee, worker: Employee, peer: Employee, other: Employee} */
    private array $org;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.user_timezone' => 'Europe/Kyiv', 'app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-14 09:00:00');
        $this->org = $this->org();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_scheduled_termination_creates_one_task_when_it_applies(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->postJson('/api/people/'.$this->org['worker']->id.'/terminate', [
            'fired_at' => '2026-07-20', 'reason' => 'Private reason', 'handover_to_employee_id' => $this->org['peer']->id,
        ])->assertOk();

        Carbon::setTestNow('2026-07-20 20:59:59'); // 23:59:59 Kyiv on the last day: nothing yet
        $this->runJob();
        $this->assertSame(0, Task::query()->count());
        Carbon::setTestNow('2026-07-20 21:00:00'); // 00:00 Kyiv on the next day
        $this->runJob();
        $this->runJob();

        $task = Task::query()->sole();
        $this->assertSame($this->userOf($this->org['peer'])->id, $task->assignee_id);
        $this->assertSame('exit_handover', $task->type->value);
        $this->assertSame('Прийняти справи: Worker Person звільнений з 20.07.2026', $task->title);
        $this->assertSame('people:handover:2026-07-20', $task->rule_key);
        $this->assertSame($this->org['worker']->id, $task->employee_id);
        $this->assertNull($task->done_at);
        $this->actingAs($this->userOf($this->org['peer']))->getJson('/api/tasks?mine=1&source=workflows')->assertOk()
            ->assertJsonPath('data.0.title', 'Прийняти справи: Worker Person звільнений з 20.07.2026');
        // a re-sent EmployeeTerminated is idempotent
        event(new EmployeeTerminated($this->org['worker']->refresh()));
        $this->assertSame(1, Task::query()->count());

        // restore closes it and clears the colleague
        $this->actingAs($admin)->postJson('/api/people/'.$this->org['worker']->id.'/restore')->assertOk()
            ->assertJsonPath('data.handover_to', null);
        $this->assertNotNull($task->fresh()?->done_at);
        $this->assertNull($this->org['worker']->refresh()->handover_to_employee_id);
    }

    public function test_termination_today_creates_the_task_at_once(): void
    {
        $this->actingAs($this->login(UserRole::HrManager))->postJson('/api/people/'.$this->org['worker']->id.'/terminate', [
            'fired_at' => '2026-07-14', 'handover_to_employee_id' => $this->org['other']->id,
        ])->assertOk()->assertJsonPath('data.status', 'terminated');

        $this->assertSame($this->userOf($this->org['other'])->id, Task::query()->sole()->assignee_id);
    }

    public function test_cancel_leaves_no_task_and_clears_the_colleague(): void
    {
        $lead = $this->userOf($this->org['lead']);
        $url = '/api/people/'.$this->org['worker']->id.'/terminate';
        $this->actingAs($lead)->postJson($url, ['fired_at' => '2026-07-20', 'handover_to_employee_id' => $this->org['peer']->id])->assertOk();
        $this->actingAs($lead)->postJson($url.'/cancel')->assertOk()->assertJsonPath('data.handover_to', null);
        $this->assertNull($this->org['worker']->refresh()->handover_to_employee_id);

        Carbon::setTestNow('2026-07-21 12:00:00');
        $this->runJob();
        $this->assertSame(0, Task::query()->whereNull('done_at')->count());
    }

    public function test_colleague_without_login_or_terminated_since_gets_no_task(): void
    {
        $admin = $this->login(UserRole::Admin);
        $noLogin = $this->employee(['full_name' => 'No Login']);
        $this->actingAs($admin)->postJson('/api/people/'.$this->org['worker']->id.'/terminate', [
            'fired_at' => '2026-07-14', 'handover_to_employee_id' => $noLogin->id,
        ])->assertOk();
        $this->actingAs($admin)->postJson('/api/people/'.$this->org['lead']->id.'/terminate', [
            'fired_at' => '2026-07-20', 'handover_to_employee_id' => $this->org['peer']->id,
        ])->assertOk();
        $this->actingAs($admin)->postJson('/api/people/'.$this->org['peer']->id.'/terminate', ['fired_at' => '2026-07-14'])->assertOk();

        Carbon::setTestNow('2026-07-21 12:00:00');
        $this->runJob();
        $this->assertSame(0, Task::query()->count());
    }

    /** @phpstan-impure */
    private function runJob(): void
    {
        $this->app->make(ScheduledTerminationJob::class)->run(Carbon::now());
    }
}
