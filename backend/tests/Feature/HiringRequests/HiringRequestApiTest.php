<?php

declare(strict_types=1);

namespace Tests\Feature\HiringRequests;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\HiringRequests\Models\HiringSettings;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Scripts\Models\Task;
use App\Modules\TimeOff\Models\Holiday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\Support\WorkflowFixtures;
use Tests\TestCase;

/**
 * Hiring requests (tz2): authz matrix, route transitions with SLA and notifications, auto-vacancy idempotency,
 * configurable form, auto-closing. Synthetic data only. Default route: requester's manager (2 days) → HR role admin (2 working days).
 */
final class HiringRequestApiTest extends TestCase
{
    use PeopleFixtures, RecruitingFixtures, RefreshDatabase, WorkflowFixtures {
        PeopleFixtures::login insteadof WorkflowFixtures;
        PeopleFixtures::employee insteadof WorkflowFixtures;
        PeopleFixtures::userOf insteadof WorkflowFixtures;
    }

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->branch = Branch::factory()->create(['name' => 'Synthetic Branch']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function payload(array $over = []): array
    {
        return $over + [
            'title' => 'QA Engineer', 'branch_id' => $this->branch->id, 'headcount' => 2, 'reason' => 'new_position',
            'priority' => 'high', 'requirements' => 'Manual and API testing', 'salary_min' => 1000, 'salary_max' => 1500, 'currency' => 'usd',
            'desired_start_date' => '2026-11-01',
        ];
    }

    public function test_authz_matrix(): void
    {
        $this->getJson('/api/hiring-requests')->assertUnauthorized();
        $org = $this->org();
        $worker = $this->userOf($org['worker']);
        $lead = $this->userOf($org['lead']);
        $admin = $this->login(UserRole::Admin);

        // Create: admins, managers (lead has reports), listed creators — not a plain employee.
        $this->actingAs($worker)->getJson('/api/hiring-requests/meta')->assertOk()->assertJsonPath('data.can_create', false);
        $this->actingAs($worker)->postJson('/api/hiring-requests', $this->payload())->assertForbidden();
        $id = $this->actingAs($lead)->postJson('/api/hiring-requests', $this->payload())->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.can.edit', true)->assertJsonPath('data.currency', 'USD')->json('data.id');

        HiringSettings::query()->firstOrFail()->update(['creator_user_ids' => [$worker->id]]);
        $this->actingAs($worker)->postJson('/api/hiring-requests', $this->payload(['title' => 'Intern']))->assertCreated();

        // See: requester and admin; unrelated colleagues get 404; the approver sees it once on the route.
        $this->actingAs($lead)->getJson("/api/hiring-requests/$id")->assertOk();
        $this->actingAs($admin)->getJson("/api/hiring-requests/$id")->assertOk()->assertJsonPath('data.can.manage', true);
        $this->actingAs($this->userOf($org['peer']))->getJson("/api/hiring-requests/$id")->assertNotFound();
        $this->actingAs($this->userOf($org['head']))->getJson("/api/hiring-requests/$id")->assertNotFound();
        $this->actingAs($lead)->postJson("/api/hiring-requests/$id/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->actingAs($this->userOf($org['head']))->getJson("/api/hiring-requests/$id")->assertOk()->assertJsonPath('data.can.decide', true);

        // Only the requester/HR edit drafts; nobody edits after submit.
        $this->actingAs($lead)->patchJson("/api/hiring-requests/$id", ['title' => 'x'])->assertStatus(409)->assertJsonPath('code', 'invalid_status');
        // The requester never approves their own request (unless admin).
        $this->actingAs($lead)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve'])->assertForbidden();
        // Settings and vacancy actions: admins only.
        $this->actingAs($lead)->getJson('/api/hiring-requests/settings')->assertForbidden();
        $this->actingAs($lead)->postJson("/api/hiring-requests/$id/vacancy")->assertForbidden();
        $this->actingAs($admin)->getJson('/api/hiring-requests/settings')->assertOk()->assertJsonCount(2, 'data.route');

        // Lists: admin all, the lead own, a plain colleague nothing.
        $this->actingAs($admin)->getJson('/api/hiring-requests')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($lead)->getJson('/api/hiring-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->userOf($org['peer']))->getJson('/api/hiring-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_route_transitions_sla_and_auto_vacancy(): void
    {
        $org = $this->org();
        $lead = $this->userOf($org['lead']);
        $head = $this->userOf($org['head']);
        $admin = $this->login(UserRole::Admin);

        $r = $this->actingAs($lead)->postJson('/api/hiring-requests', $this->payload(['submit' => true]))->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_step.name', 'Manager')
            ->assertJsonPath('data.approvals.0.approver.id', $head->id)
            ->assertJsonPath('data.approvals.0.due_at', '2026-10-07T09:00:00+00:00')
            ->assertJsonPath('data.approvals.1.status', 'waiting');
        $id = $r->json('data.id');
        // Notification: a task for the manager, once.
        $this->assertSame(1, Task::query()->where('type', 'hiring_approval')->where('assignee_id', $head->id)->count());
        $this->actingAs($head)->getJson('/api/hiring-requests/inbox')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/hiring-requests/inbox')->assertOk()->assertJsonCount(1, 'data'); // HR may override any step
        $this->actingAs($head)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.hiring.my_approvals.count', 1);

        // SLA: 2 working days (Mon → Wed) → overdue on Thursday; the job escalates to HR once.
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->actingAs($head)->getJson("/api/hiring-requests/$id")->assertJsonPath('data.overdue', true)->assertJsonPath('data.approvals.0.overdue', true);
        $this->assertSame(1, $this->runJob()['hiring_escalated']);
        $this->assertSame(0, $this->runJob()['hiring_escalated'], 'escalation is sent once');
        $this->assertSame(1, Task::query()->where('rule_key', 'like', 'hrq-sla:%')->count());

        // Manager approves → HR step pending, manager's tasks closed, HR notified.
        $this->actingAs($head)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve', 'comment' => 'ok'])->assertOk()
            ->assertJsonPath('data.approvals.0.status', 'approved')->assertJsonPath('data.approvals.0.decided_by.id', $head->id)
            ->assertJsonPath('data.approvals.1.status', 'pending')->assertJsonPath('data.status', 'pending');
        $this->assertNotNull(Task::query()->where('assignee_id', $head->id)->where('rule_key', 'like', 'hrq:%')->value('done_at'));
        $this->assertSame(1, Task::query()->where('assignee_id', $admin->id)->where('rule_key', 'like', 'hrq:%')->whereNull('done_at')->count());
        // The same step cannot be decided twice (409), a non-approver cannot decide (403).
        $this->actingAs($head)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->userOf($org['peer']))->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve'])->assertNotFound();

        // HR approves (final) → approved → vacancy opened, prefilled and linked → in_progress.
        $recruiter = $this->login(UserRole::Recruiter);
        $done = $this->actingAs($admin)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve', 'recruiter_id' => $recruiter->id])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.recruiter.id', $recruiter->id)
            ->assertJsonPath('data.progress.hired', 0)->assertJsonPath('data.progress.headcount', 2);
        $vacancy = Vacancy::query()->findOrFail($done->json('data.vacancy.id'));
        $this->assertSame('QA Engineer', $vacancy->title);
        $this->assertSame($this->branch->id, $vacancy->branch_id);
        $this->assertSame($recruiter->id, $vacancy->recruiter_id);
        $this->assertStringContainsString('Manual and API testing', (string) $vacancy->description);
        $this->assertStringContainsString('Кількість позицій: 2', (string) $vacancy->description);

        // Idempotent vacancy creation: repeated calls keep one vacancy.
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/vacancy")->assertOk()->assertJsonPath('data.vacancy.id', $vacancy->id);
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/vacancy")->assertOk();
        $this->assertSame(1, Vacancy::query()->count());

        // Progress: hires count vs headcount; hiring the headcount closes the request (job, idempotent).
        $hireStage = $this->defaultPipeline()->stages->first(fn ($s) => $s->isHire());
        foreach (['First Hire', 'Second Hire'] as $name) {
            $this->applied($vacancy, ['full_name' => $name])->update(['stage_id' => $hireStage->id, 'status' => 'hired']);
        }
        $this->actingAs($admin)->getJson("/api/hiring-requests/$id")->assertJsonPath('data.progress.hired', 2)->assertJsonPath('data.progress.percent', 100);
        $this->assertSame(1, $this->runJob()['hiring_closed']);
        $this->assertSame(0, $this->runJob()['hiring_closed']);
        $this->assertSame('closed', HiringRequest::query()->findOrFail($id)->status->value);
    }

    public function test_sla_counts_working_days_friday_to_tuesday_with_branch_holiday(): void
    {
        $org = $this->org();
        $lead = $this->userOf($org['lead']);
        $this->login(UserRole::Admin);
        Holiday::query()->create(['date' => '2026-10-12', 'name' => 'Synthetic branch holiday', 'branch_id' => $this->branch->id]);

        // Friday 10:00 + 2 working days: Saturday/Sunday skipped, Monday is the branch's holiday → Wednesday 10:00.
        Carbon::setTestNow('2026-10-09 10:00:00');
        $id = $this->actingAs($lead)->postJson('/api/hiring-requests', $this->payload(['submit' => true]))->assertCreated()
            ->assertJsonPath('data.approvals.0.due_at', '2026-10-14T10:00:00+00:00')->json('data.id');

        // Over the weekend and on the holiday nothing is overdue and nothing escalates.
        Carbon::setTestNow('2026-10-12 18:00:00');
        $this->actingAs($lead)->getJson("/api/hiring-requests/$id")->assertJsonPath('data.approvals.0.overdue', false);
        $this->assertSame(0, $this->runJob()['hiring_escalated']);
        Carbon::setTestNow('2026-10-14 10:30:00');
        $this->actingAs($lead)->getJson("/api/hiring-requests/$id")->assertJsonPath('data.approvals.0.overdue', true);
        $this->assertSame(1, $this->runJob()['hiring_escalated']);
    }

    public function test_sla_without_holidays_friday_to_tuesday(): void
    {
        $org = $this->org();
        $lead = $this->userOf($org['lead']);
        Carbon::setTestNow('2026-10-09 10:00:00');
        $this->actingAs($lead)->postJson('/api/hiring-requests', $this->payload(['submit' => true]))->assertCreated()
            ->assertJsonPath('data.approvals.0.due_at', '2026-10-13T10:00:00+00:00');
    }

    public function test_reject_cancel_and_manager_skip(): void
    {
        $org = $this->org();
        $admin = $this->login(UserRole::Admin);
        $head = $this->userOf($org['head']);

        // head has no manager → the manager step is skipped, HR is first.
        $id = $this->actingAs($head)->postJson('/api/hiring-requests', $this->payload(['submit' => true]))->assertCreated()
            ->assertJsonPath('data.approvals.0.status', 'skipped')->assertJsonPath('data.current_step.name', 'HR')->json('data.id');
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'reject'])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'reject', 'comment' => 'Budget frozen'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.approvals.1.comment', 'Budget frozen');
        $this->assertSame(0, Vacancy::query()->count());
        $this->actingAs($head)->postJson("/api/hiring-requests/$id/cancel")->assertStatus(409);

        // Replacement needs the replaced employee on submit; cancel of a pending request skips the route.
        $id2 = $this->actingAs($head)->postJson('/api/hiring-requests', $this->payload(['reason' => 'replacement']))->assertCreated()->json('data.id');
        $this->actingAs($head)->postJson("/api/hiring-requests/$id2/submit")->assertUnprocessable()->assertJsonPath('code', 'replaced_employee_required');
        $this->actingAs($head)->patchJson("/api/hiring-requests/$id2", ['replaced_employee_id' => $org['other']->id])->assertOk()
            ->assertJsonPath('data.replaced_employee.full_name', 'Other Person');
        $this->actingAs($head)->postJson("/api/hiring-requests/$id2/submit")->assertOk();
        $this->actingAs($head)->postJson("/api/hiring-requests/$id2/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.approvals.1.status', 'skipped');
        $this->assertSame(0, Task::query()->where('rule_key', 'like', 'hrq:%')->whereNull('done_at')->count(), 'tasks of a cancelled request are closed');

        // Salary range is checked.
        $this->actingAs($head)->postJson('/api/hiring-requests', $this->payload(['salary_min' => 2000, 'salary_max' => 1000]))
            ->assertUnprocessable()->assertJsonPath('code', 'salary_range');
    }

    public function test_configurable_form_route_and_manual_vacancy_link(): void
    {
        $admin = $this->login(UserRole::Admin);
        $director = $this->login(UserRole::Recruiter);
        $this->actingAs($admin)->putJson('/api/hiring-requests/settings', [
            'form_fields' => [
                ['key' => 'budget_code', 'label' => 'Budget code', 'type' => 'text', 'required' => true],
                ['key' => 'contract', 'label' => 'Contract', 'type' => 'select', 'required' => false, 'options' => ['full_time', 'part_time']],
            ],
            'auto_vacancy' => false,
            'route' => [
                ['name' => 'Branch director', 'kind' => 'user', 'user_id' => $director->id, 'sla_days' => 1],
                ['name' => 'HR', 'kind' => 'role', 'role' => 'admin', 'sla_days' => 2],
            ],
        ])->assertOk()->assertJsonPath('data.route.0.user.id', $director->id)->assertJsonPath('data.form_fields.1.options.1', 'part_time');
        $this->actingAs($admin)->putJson('/api/hiring-requests/settings', ['route' => [['name' => 'X', 'kind' => 'role', 'role' => 'nope']]])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_route');
        $this->actingAs($admin)->putJson('/api/hiring-requests/settings', ['form_fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text'], ['key' => 'a', 'label' => 'B', 'type' => 'text']]])
            ->assertUnprocessable();

        $manager = $this->org()['lead'];
        $lead = $this->userOf($manager);
        $this->actingAs($lead)->postJson('/api/hiring-requests', $this->payload(['extra' => ['contract' => 'weekly']]))
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_field');
        $id = $this->actingAs($lead)->postJson('/api/hiring-requests', $this->payload(['extra' => ['contract' => 'part_time', 'unknown' => 'dropped']]))
            ->assertCreated()->assertJsonPath('data.extra.contract', 'part_time')->assertJsonMissingPath('data.extra.unknown')->json('data.id');
        $this->actingAs($lead)->postJson("/api/hiring-requests/$id/submit")->assertUnprocessable()
            ->assertJsonPath('code', 'required_fields')->assertJsonPath('fields.0', 'budget_code');
        $this->actingAs($lead)->patchJson("/api/hiring-requests/$id", ['extra' => ['budget_code' => 'SYN-01', 'contract' => 'part_time']])->assertOk();
        $this->actingAs($lead)->postJson("/api/hiring-requests/$id/submit")->assertOk()->assertJsonPath('data.current_step.name', 'Branch director');

        $this->actingAs($director)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve'])->assertOk();
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve'])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.vacancy', null);

        // The vacancy form's "hiring request" field: link an existing vacancy; a vacancy links to one request only.
        $vacancy = $this->vacancyIn($this->branch, $director);
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/link-vacancy", ['vacancy_id' => $vacancy->id])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.vacancy.id', $vacancy->id);
        $vacancy->update(['status' => 'closed']);
        $this->assertSame(1, $this->runJob()['hiring_closed'], 'a closed vacancy closes the request');
    }

    /** @return array<string, mixed> */
    private function runJob(): array
    {
        config(['ops.secret' => self::OPS_SECRET]);
        $jobs = $this->postJson(self::OPS_URL, [], ['X-Ops-Secret' => self::OPS_SECRET])->assertOk()->json('jobs');
        $this->assertIsArray($jobs);
        $this->assertTrue($jobs['hiring.sla']['ok']);

        return $jobs['hiring.sla'];
    }
}
