<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Integrations\Contracts\HostResolver;
use App\Modules\People\Events\EmployeeHired;
use App\Modules\People\Models\Employee;
use App\Modules\Scripts\Models\Task;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use App\Modules\Workflows\Support\WebhookSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;
use Tests\Support\GoogleFixtures;
use Tests\Support\PeopleFixtures;
use Tests\Support\WorkflowFixtures;
use Tests\TestCase;

/** Runs: triggers, snapshots, due execution through the ops job, executors, retry, nesting, access. Synthetic data. */
final class WorkflowRunsTest extends TestCase
{
    use GoogleFixtures, PeopleFixtures, RefreshDatabase, WorkflowFixtures;

    private const string HOOK = 'https://hooks.example.test/in';

    private const string SECRET = 'synthetic-signing-key-0042';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->app->instance(HostResolver::class, new FakeHostResolver);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hire_starts_onboarding_once_per_employee(): void
    {
        $template = $this->workflow([['create_task', 0, 'employee']], ['trigger' => 'employee_hired']);
        $this->workflow([['create_task']], ['trigger' => 'employee_hired', 'active' => false]);
        $admin = $this->login(UserRole::Admin);

        $id = $this->actingAs($admin)->postJson('/api/people', ['full_name' => 'New Person', 'hired_at' => '2026-10-07'])
            ->assertCreated()->json('data.id');
        $employee = $this->employeeModel((int) $id);
        // The same event again (e.g. a retried request) must not start a second run.
        event(new EmployeeHired($employee));

        $runs = WorkflowRun::query()->where('employee_id', $id)->get();
        $this->assertCount(1, $runs);
        $run = $runs->first();
        $this->assertNotNull($run);
        $this->assertSame($template->id, $run->template_id);
        $this->assertSame('2026-10-07', $run->anchor_date->toDateString());
        $this->assertSame('employee_hired', $run->trigger_key);
        $this->assertNull($run->started_by);
    }

    public function test_termination_starts_offboarding_anchored_on_the_last_day(): void
    {
        $this->workflow([['create_task', -1, 'hr_admin']], ['kind' => 'offboarding', 'trigger' => 'employee_terminated']);
        $admin = $this->login(UserRole::Admin);
        $employee = $this->employee();

        $this->actingAs($admin)->postJson("/api/people/{$employee->id}/terminate", ['fired_at' => '2026-10-20'])->assertOk();

        $run = WorkflowRun::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame('2026-10-20', $run->anchor_date->toDateString());
        $step = WorkflowRunStep::query()->where('run_id', $run->id)->sole();
        $this->assertSame('2026-10-19 00:00:00', $step->due_at->format('Y-m-d H:i:s'));
        $this->assertSame($admin->id, $step->assignee_id);
    }

    public function test_run_keeps_its_snapshot_when_the_template_changes(): void
    {
        $admin = $this->login(UserRole::Admin);
        $template = $this->workflow([['create_task', 0, 'hr_admin', ['title' => 'Original task'], 'Original']]);
        $employee = $this->employee();
        $runId = $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $employee->id])
            ->assertCreated()->assertJsonPath('data.steps.0.title', 'Original')->json('data.id');

        $this->actingAs($admin)->putJson("/api/workflows/templates/{$template->id}", [
            'name' => 'Changed', 'kind' => 'onboarding', 'trigger' => 'manual',
            'steps' => [['title' => 'Changed', 'action' => 'notify_manager', 'offset_days' => 9, 'assignee_rule' => 'manager', 'config' => []]],
        ])->assertOk();
        $this->tick();

        $this->actingAs($admin)->getJson("/api/workflows/runs/$runId")->assertOk()
            ->assertJsonPath('data.template.name', 'Onboarding sample')
            ->assertJsonPath('data.steps.0.title', 'Original')
            ->assertJsonPath('data.steps.0.action', 'create_task')
            ->assertJsonPath('data.steps.0.waiting', true);
        $this->assertSame('Original task', Task::query()->sole()->title);
    }

    public function test_due_steps_execute_through_the_ops_job_once(): void
    {
        $admin = $this->login(UserRole::Admin);
        $user = $this->login(UserRole::Viewer);
        $employee = $this->employee([], $user);
        $template = $this->workflow([
            ['create_task', 0, 'employee', ['title' => 'Sign the rules']],
            ['upload_document_request', 5, 'employee', ['document_name' => 'Passport copy'], 'Bring documents'],
        ]);
        $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $employee->id])->assertCreated();
        $this->assertSame(0, Task::query()->count(), 'nothing runs at start');

        $this->assertSame(1, $this->tick()['executed']);
        $this->assertSame(0, $this->tick()['executed'], 'a second tick does not repeat the step');
        $task = Task::query()->sole();
        $this->assertSame($user->id, $task->assignee_id);
        $this->assertSame($employee->id, $task->employee_id);
        $this->assertSame('workflow', $task->type->value);
        $this->assertSame('/people/'.$employee->id, $task->link);

        $this->travel(5)->days();
        $this->assertSame(1, $this->tick()['executed']);
        $second = Task::query()->where('id', '!=', $task->id)->sole();
        $this->assertSame('Bring documents: Passport copy', $second->title);
        $this->assertSame('/people/'.$employee->id.'?tab=documents', $second->link);

        // The employee (role viewer) sees and closes their tasks in "Мої задачі"; the steps and the run complete.
        $this->actingAs($user)->getJson('/api/tasks?mine=1&source=workflows')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', 'workflows')
            ->assertJsonPath('data.0.employee.id', $employee->id);
        $this->actingAs($user)->getJson('/api/tasks?mine=1&source=recruiting')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->patchJson("/api/tasks/{$task->id}", ['done' => true])->assertOk();
        $this->actingAs($user)->patchJson("/api/tasks/{$second->id}", ['done' => true])->assertOk();
        $run = WorkflowRun::query()->sole();
        $this->assertSame('completed', $run->status->value);
        $this->assertSame($user->id, WorkflowRunStep::query()->where('run_id', $run->id)->orderBy('position')->firstOrFail()->completed_by);
    }

    public function test_employee_without_login_gets_the_task_routed_to_hr(): void
    {
        $admin = $this->login(UserRole::Admin);
        $employee = $this->employee();
        $template = $this->workflow([['create_task', 0, 'employee']]);
        $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $employee->id])->assertCreated();

        $this->tick();

        $step = WorkflowRunStep::query()->sole();
        $this->assertSame($admin->id, Task::query()->sole()->assignee_id);
        $this->assertTrue($step->result['assignee_fallback'] ?? false);
    }

    public function test_webhook_is_signed_with_the_template_key(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response(['ok' => true])]);
        $this->captureLogs();
        [$run] = $this->startWebhookRun();

        $this->assertSame(1, $this->tick()['done']);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($run): bool {
            $payload = json_decode($request->body(), true);

            return $request->url() === self::HOOK
                && $request->method() === 'POST'
                && $request->hasHeader('X-SinHRM-Signature', 'sha256='.hash_hmac('sha256', $request->body(), self::SECRET))
                && $request->hasHeader('X-SinHRM-Event', 'workflow.step')
                && is_array($payload) && $payload['run_id'] === $run->id && $payload['event'] === 'workflow.step';
        });
        $step = WorkflowRunStep::query()->sole();
        $this->assertSame('done', $step->status->value);
        $this->assertSame(['http_status' => 200], $step->result);
        $this->assertLogsDoNotContain(self::SECRET);
    }

    public function test_webhook_to_a_private_address_is_refused_before_any_request_and_can_be_retried(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response(['ok' => true])]);
        $this->app->instance(HostResolver::class, new FakeHostResolver(['hooks.example.test' => ['10.0.0.5']]));
        $this->captureLogs();
        [$run, $admin] = $this->startWebhookRun();

        $this->assertSame(1, $this->tick()['failed']);
        Http::assertNothingSent();
        $step = WorkflowRunStep::query()->sole();
        $this->assertSame('failed', $step->status->value);
        $this->assertSame(['error' => 'blocked_host'], $step->result);
        $this->actingAs($admin)->getJson("/api/workflows/runs/{$run->id}")->assertOk()
            ->assertJsonPath('data.has_failed', true)->assertJsonPath('data.steps.0.can_retry', true);
        $this->assertSame(0, $this->tick()['executed'], 'a failed step is not retried by the cron');

        // DNS fixed → retry runs it right away.
        $this->app->instance(HostResolver::class, new FakeHostResolver);
        $this->actingAs($this->login(UserRole::Recruiter))->postJson("/api/workflows/runs/{$run->id}/steps/{$step->id}/retry")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/workflows/runs/{$run->id}/steps/{$step->id}/retry")->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.run_status', 'completed');
        $this->assertSame(2, $step->refresh()->attempts);
        $this->actingAs($admin)->postJson("/api/workflows/runs/{$run->id}/steps/{$step->id}/retry")->assertStatus(409);
        $this->assertLogsDoNotContain(self::SECRET, self::HOOK);
    }

    public function test_webhook_failures_without_key_or_with_http_error(): void
    {
        Http::fake(['hooks.example.test/*' => Http::sequence()->push('nope', 500)->push('ok', 200)]);
        [$run, $admin] = $this->startWebhookRun(withSecret: false);

        $this->tick();
        $step = WorkflowRunStep::query()->sole();
        $this->assertSame(['error' => 'missing_secret'], $step->result);
        Http::assertNothingSent();

        $this->app->make(WebhookSecrets::class)->put($run->template_id, self::SECRET, $admin->id);
        $this->actingAs($admin)->postJson("/api/workflows/runs/{$run->id}/steps/{$step->id}/retry")->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.result.error', 'http_500');
        $this->actingAs($admin)->postJson("/api/workflows/runs/{$run->id}/steps/{$step->id}/retry")->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    public function test_nested_workflows_stop_at_depth_three(): void
    {
        $admin = $this->login(UserRole::Admin);
        $employee = $this->employee();
        $template = $this->workflow([['create_task']]);
        // The template starts itself: only the depth limit stops the loop.
        $this->workflow([], ['name' => 'unused']);
        $template->steps()->delete();
        $template->steps()->create(['position' => 0, 'title' => 'Again', 'action' => 'start_workflow', 'offset_days' => 0, 'assignee_rule' => 'hr_admin', 'config' => ['template_id' => $template->id]]);
        $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $employee->id])->assertCreated();

        for ($i = 0; $i < 6; $i++) {
            $this->tick();
        }

        $runs = WorkflowRun::query()->orderBy('depth')->get();
        $this->assertSame([0, 1, 2, 3], $runs->pluck('depth')->all());
        $this->assertSame($runs[2]->id, $runs[3]->parent_run_id);
        $last = WorkflowRunStep::query()->where('run_id', $runs[3]->id)->sole();
        $this->assertSame('failed', $last->status->value);
        $this->assertSame(['error' => 'depth_limit'], $last->result);
    }

    public function test_google_actions_skip_when_not_connected_and_notify_manager_needs_a_manager(): void
    {
        $admin = $this->login(UserRole::Admin);
        $employee = $this->employee();
        $template = $this->workflow([
            ['send_email_template', 0, 'hr_admin', ['subject' => 'Hi', 'body' => 'Welcome']],
            ['add_calendar_event', 0, 'hr_admin', ['time' => '10:00']],
            ['notify_manager', 0, 'manager', ['message' => 'Meet your new colleague']],
        ]);
        $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $employee->id])->assertCreated();

        $this->assertSame(3, $this->tick()['skipped']);

        $results = WorkflowRunStep::query()->orderBy('position')->pluck('result')->all();
        $this->assertSame([['reason' => 'not_connected'], ['reason' => 'not_connected'], ['reason' => 'no_manager']], $results);
        $this->assertSame('completed', WorkflowRun::query()->sole()->status->value);
        Http::assertNothingSent();
    }

    public function test_notify_manager_creates_a_notice_for_the_manager_without_blocking(): void
    {
        $admin = $this->login(UserRole::Admin);
        $org = $this->org();
        $template = $this->workflow([['notify_manager', 0, 'hr_admin', ['message' => 'Meet your new colleague']]]);
        $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $org['worker']->id])->assertCreated();

        $this->tick();

        $task = Task::query()->sole();
        $this->assertSame($org['lead']->user_id, $task->assignee_id);
        $this->assertSame('Meet your new colleague', $task->title);
        $this->assertSame('completed', WorkflowRun::query()->sole()->status->value);
    }

    public function test_create_document_step_generates_and_sends_the_document(): void
    {
        $admin = $this->login(UserRole::Admin);
        $user = $this->login(UserRole::Viewer);
        $employee = $this->employee(['full_name' => 'Olena Sample'], $user);
        $docTemplate = DocumentTemplate::query()->create(['name' => 'Rules', 'body' => "# Rules\n\nDear {Ім'я}, welcome."]);
        $template = $this->workflow([['create_document', 0, 'hr_admin', ['document_template_id' => $docTemplate->id, 'send' => true]]]);
        $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $employee->id])->assertCreated();

        $this->tick();

        $document = Document::query()->sole();
        $this->assertSame('sent', $document->status->value);
        $this->assertStringContainsString('Dear Olena, welcome.', (string) $document->content_md);
        $step = WorkflowRunStep::query()->sole();
        $this->assertSame(['document_id' => $document->id, 'sent' => true], $step->result);
        $this->assertSame($user->id, Task::query()->where('type', 'document')->sole()->assignee_id);
    }

    public function test_probation_end_starts_once_inside_the_window(): void
    {
        $this->workflow([['create_task']], ['trigger' => 'probation_end', 'probation_days' => 90]);
        $due = $this->employee(['hired_at' => Carbon::today()->subDays(92)->toDateString()]);
        $this->employee(['hired_at' => Carbon::today()->subDays(30)->toDateString()]);
        $this->employee(['hired_at' => Carbon::today()->subDays(200)->toDateString()]);

        $this->assertSame(1, $this->tick()['probation_started']);
        $this->assertSame(0, $this->tick()['probation_started']);
        $run = WorkflowRun::query()->sole();
        $this->assertSame($due->id, $run->employee_id);
        $this->assertSame(Carbon::today()->subDays(2)->toDateString(), $run->anchor_date->toDateString());
    }

    public function test_runs_access_matrix(): void
    {
        $admin = $this->login(UserRole::Admin);
        $org = $this->org();
        $template = $this->workflow([['create_task', 3, 'manager']]);
        $runId = $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $org['worker']->id])
            ->assertCreated()->json('data.id');

        foreach (['head', 'lead'] as $manager) {
            $user = $this->userOf($org[$manager]);
            $this->actingAs($user)->getJson("/api/workflows/runs/$runId")->assertOk()->assertJsonPath('data.can_cancel', false);
            $this->actingAs($user)->getJson('/api/workflows/runs')->assertOk()->assertJsonCount(1, 'data');
        }
        foreach (['worker', 'peer', 'other'] as $outsider) {
            $user = $this->userOf($org[$outsider]);
            $this->actingAs($user)->getJson("/api/workflows/runs/$runId")->assertNotFound();
            $this->actingAs($user)->getJson('/api/workflows/runs')->assertOk()->assertJsonCount(0, 'data');
            $this->actingAs($user)->postJson("/api/workflows/runs/$runId/cancel")->assertForbidden();
        }
        $this->actingAs($admin)->getJson('/api/workflows/runs?employee_id='.$org['worker']->id.'&status=running')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/workflows/runs?employee_id='.$org['peer']->id)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/workflows/runs?status=done')->assertUnprocessable();
    }

    public function test_complete_and_skip_by_assignee_or_admin_only(): void
    {
        $admin = $this->login(UserRole::Admin);
        $org = $this->org();
        $template = $this->workflow([['create_task', 0, 'manager'], ['create_task', 10, 'manager'], ['create_task', 10, 'manager']]);
        $run = $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $org['worker']->id])->json('data');
        $this->assertIsArray($run);
        [$first, $second, $third] = array_column($run['steps'], 'id');
        $lead = $this->userOf($org['lead']);
        $this->assertSame($lead->id, $run['steps'][0]['assignee']['id']);
        $this->tick();

        $this->actingAs($this->userOf($org['peer']))->postJson("/api/workflows/runs/{$run['id']}/steps/$first/complete")->assertForbidden();
        $this->actingAs($this->userOf($org['worker']))->postJson("/api/workflows/runs/{$run['id']}/steps/$first/skip")->assertForbidden();
        $this->actingAs($lead)->postJson("/api/workflows/runs/{$run['id']}/steps/$first/complete")->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertNotNull(Task::query()->sole()->done_at, 'the linked task is closed too');
        $this->actingAs($lead)->postJson("/api/workflows/runs/{$run['id']}/steps/$first/complete")->assertStatus(409)->assertJsonPath('code', 'step_not_open');
        // A step that is not due yet can be finished early by hand.
        $this->actingAs($admin)->postJson("/api/workflows/runs/{$run['id']}/steps/$second/skip", ['reason' => 'not needed'])->assertOk()
            ->assertJsonPath('data.status', 'skipped')->assertJsonPath('data.result.reason', 'not needed');
        $this->actingAs($admin)->postJson("/api/workflows/runs/{$run['id']}/steps/$third/complete")->assertOk()
            ->assertJsonPath('data.run_status', 'completed');
        // A step of another run under this URL → 404.
        $this->actingAs($admin)->postJson('/api/workflows/runs/999/steps/'.$first.'/complete')->assertNotFound();
    }

    public function test_cancel_skips_open_steps_and_closes_their_tasks(): void
    {
        $admin = $this->login(UserRole::Admin);
        $employee = $this->employee();
        $template = $this->workflow([['create_task'], ['create_task', 7]]);
        $runId = $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $employee->id, 'anchor_date' => '2026-10-05'])->json('data.id');
        $this->tick();

        $this->actingAs($admin)->postJson("/api/workflows/runs/$runId/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.steps.0.status', 'skipped')
            ->assertJsonPath('data.steps.1.result.reason', 'cancelled');
        $this->assertNotNull(Task::query()->sole()->done_at);
        $this->actingAs($admin)->postJson("/api/workflows/runs/$runId/cancel")->assertStatus(409)->assertJsonPath('code', 'run_not_running');
        $this->travel(10)->days();
        $this->assertSame(0, $this->tick()['executed']);
    }

    public function test_start_validation(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => 999, 'employee_id' => 999, 'anchor_date' => '05.10.2026'])
            ->assertUnprocessable()->assertJsonValidationErrors(['template_id', 'employee_id', 'anchor_date']);
    }

    /** @return array{0: WorkflowRun, 1: User} */
    private function startWebhookRun(bool $withSecret = true): array
    {
        $admin = $this->login(UserRole::Admin);
        $template = $this->workflow([['webhook', 0, 'hr_admin', ['url' => self::HOOK]]]);
        if ($withSecret) {
            $this->actingAs($admin)->putJson("/api/workflows/templates/{$template->id}/webhook-secret", ['secret' => self::SECRET])->assertOk();
        }
        $employee = $this->employee(['full_name' => 'Hook Person']);
        $id = $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $employee->id])
            ->assertCreated()->json('data.id');

        return [WorkflowRun::query()->findOrFail($id), $admin];
    }

    private function employeeModel(int $id): Employee
    {
        return Employee::query()->findOrFail($id);
    }
}
