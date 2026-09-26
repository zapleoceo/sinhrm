<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Workflows\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\Support\WorkflowFixtures;
use Tests\TestCase;

/** Templates API: admin-only CRUD with inline steps, per-action config validation, reorder, webhook key. */
final class WorkflowTemplatesApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase, WorkflowFixtures;

    public function test_guest_gets_401_and_non_admins_403(): void
    {
        $this->getJson('/api/workflows/templates')->assertUnauthorized();
        $this->postJson('/api/workflows/templates', [])->assertUnauthorized();
        $this->getJson('/api/workflows/runs')->assertUnauthorized();

        foreach ([UserRole::Recruiter, UserRole::Viewer] as $role) {
            $user = $this->login($role);
            $this->actingAs($user)->getJson('/api/workflows/templates')->assertForbidden();
            $this->actingAs($user)->postJson('/api/workflows/templates', $this->payload())->assertForbidden();
            $this->actingAs($user)->postJson('/api/workflows/runs', [])->assertForbidden();
        }
    }

    public function test_admin_creates_updates_and_lists_a_template_with_steps(): void
    {
        $admin = $this->login(UserRole::Admin);

        $created = $this->actingAs($admin)->postJson('/api/workflows/templates', $this->payload())->assertCreated()
            ->assertJsonPath('data.name', 'New hire')
            ->assertJsonPath('data.trigger', 'employee_hired')
            ->assertJsonPath('data.webhook_secret.is_set', false)
            ->assertJsonCount(3, 'data.steps')
            ->assertJsonPath('data.steps.0.action', 'create_task')
            ->assertJsonPath('data.steps.1.offset_days', -2)
            ->assertJsonPath('data.steps.2.config.url', 'https://hooks.example.test/in');
        $id = $created->json('data.id');
        $steps = $created->json('data.steps');
        $this->assertIsArray($steps);

        // Update: keep step 1 (renamed), drop step 2, add a new one; unknown config keys are dropped.
        $payload = $this->payload();
        $payload['name'] = 'New hire v2';
        $payload['steps'] = [
            ['id' => $steps[2]['id'], 'title' => 'Hook', 'action' => 'webhook', 'offset_days' => 0, 'assignee_rule' => 'hr_admin', 'config' => ['url' => 'https://hooks.example.test/in', 'token' => 'must-not-be-stored']],
            ['id' => $steps[0]['id'], 'title' => 'Prepare desk (renamed)', 'action' => 'create_task', 'offset_days' => 1, 'assignee_rule' => 'manager', 'config' => []],
            ['title' => 'Buddy', 'action' => 'assign_buddy', 'offset_days' => 3, 'assignee_rule' => 'manager', 'config' => ['title' => 'Pick a buddy']],
        ];
        $this->actingAs($admin)->putJson("/api/workflows/templates/$id", $payload)->assertOk()
            ->assertJsonPath('data.name', 'New hire v2')
            ->assertJsonCount(3, 'data.steps')
            ->assertJsonPath('data.steps.0.id', $steps[2]['id'])
            ->assertJsonPath('data.steps.0.config', ['url' => 'https://hooks.example.test/in'])
            ->assertJsonPath('data.steps.1.id', $steps[0]['id'])
            ->assertJsonPath('data.steps.1.title', 'Prepare desk (renamed)')
            ->assertJsonPath('data.steps.2.action', 'assign_buddy');
        $this->assertDatabaseMissing('workflow_steps', ['id' => $steps[1]['id']]);

        $this->actingAs($admin)->getJson('/api/workflows/templates')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.runs_count', 0);
    }

    public function test_step_config_is_validated_by_its_action(): void
    {
        $admin = $this->login(UserRole::Admin);
        $bad = [
            ['webhook', [], 'steps.0.config.url'],
            ['webhook', ['url' => 'http://plain.example.test'], 'steps.0.config.url'],
            ['create_document', [], 'steps.0.config.document_template_id'],
            ['create_document', ['document_template_id' => 999], 'steps.0.config.document_template_id'],
            ['start_workflow', ['template_id' => 999], 'steps.0.config.template_id'],
            ['send_email_template', ['subject' => 'Hi'], 'steps.0.config.body'],
            ['add_calendar_event', ['time' => '25:99'], 'steps.0.config.time'],
            ['upload_document_request', [], 'steps.0.config.document_name'],
            ['request_form', ['url' => 'javascript:alert(1)'], 'steps.0.config.url'],
        ];
        foreach ($bad as [$action, $config, $key]) {
            $payload = $this->payload();
            $payload['steps'] = [['title' => 'x', 'action' => $action, 'offset_days' => 0, 'assignee_rule' => 'hr_admin', 'config' => $config]];
            $this->actingAs($admin)->postJson('/api/workflows/templates', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors([$key]);
        }

        $payload = $this->payload();
        $payload['steps'] = [['title' => 'x', 'action' => 'create_task', 'offset_days' => 999, 'assignee_rule' => 'specific_user', 'config' => []]];
        $this->actingAs($admin)->postJson('/api/workflows/templates', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['steps.0.offset_days', 'steps.0.assignee_user_id']);
        foreach (['kind' => 'other', 'trigger' => 'birthday', 'name' => ''] as $field => $value) {
            $this->actingAs($admin)->postJson('/api/workflows/templates', [$field => $value] + $this->payload())
                ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
    }

    public function test_reorder_requires_exactly_the_template_steps(): void
    {
        $admin = $this->login(UserRole::Admin);
        $template = $this->workflow([['create_task'], ['assign_buddy'], ['notify_manager']]);
        $ids = WorkflowStep::query()->where('template_id', $template->id)->orderBy('position')->pluck('id')->all();

        $this->actingAs($admin)->postJson("/api/workflows/templates/{$template->id}/steps/reorder", ['ids' => [$ids[2], $ids[0], $ids[1]]])
            ->assertOk()
            ->assertJsonPath('data.steps.0.id', $ids[2])
            ->assertJsonPath('data.steps.2.id', $ids[1]);
        $this->actingAs($admin)->postJson("/api/workflows/templates/{$template->id}/steps/reorder", ['ids' => [$ids[0], $ids[1]]])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_order');
        $this->actingAs($admin)->postJson("/api/workflows/templates/{$template->id}/steps/reorder", ['ids' => [$ids[0], $ids[0], $ids[1]]])
            ->assertUnprocessable();
    }

    public function test_webhook_secret_is_stored_in_the_vault_and_never_returned(): void
    {
        $admin = $this->login(UserRole::Admin);
        $template = $this->workflow([['webhook', 0, 'hr_admin', ['url' => 'https://hooks.example.test/in']]]);
        $secret = 'synthetic-signing-key-0042';

        $response = $this->actingAs($admin)->putJson("/api/workflows/templates/{$template->id}/webhook-secret", ['secret' => $secret])
            ->assertOk()->assertJsonPath('data.webhook_secret.is_set', true);
        $this->assertStringNotContainsString($secret, (string) $response->getContent());
        $this->assertStringNotContainsString($secret, (string) $this->actingAs($admin)->getJson('/api/workflows/templates')->getContent());
        $this->assertDatabaseMissing('integration_secrets', ['value' => $secret]);

        $this->actingAs($admin)->putJson("/api/workflows/templates/{$template->id}/webhook-secret", ['secret' => 'short'])->assertUnprocessable();
        $this->actingAs($admin)->putJson("/api/workflows/templates/{$template->id}/webhook-secret", ['secret' => null])
            ->assertOk()->assertJsonPath('data.webhook_secret.is_set', false);
    }

    public function test_delete_only_without_runs(): void
    {
        $admin = $this->login(UserRole::Admin);
        $unused = $this->workflow([['create_task']]);
        $used = $this->workflow([['create_task']]);
        $employee = $this->employee();
        $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $used->id, 'employee_id' => $employee->id])->assertCreated();

        $this->actingAs($admin)->deleteJson("/api/workflows/templates/{$unused->id}")->assertNoContent();
        $this->actingAs($admin)->deleteJson("/api/workflows/templates/{$used->id}")->assertStatus(409)->assertJsonPath('code', 'has_runs');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'name' => 'New hire',
            'kind' => 'onboarding',
            'trigger' => 'employee_hired',
            'active' => true,
            'steps' => [
                ['title' => 'Prepare desk', 'action' => 'create_task', 'offset_days' => 0, 'assignee_rule' => 'hr_admin', 'config' => ['title' => 'Prepare the desk']],
                ['title' => 'Welcome e-mail', 'action' => 'send_email_template', 'offset_days' => -2, 'assignee_rule' => 'hr_admin', 'config' => ['subject' => 'Welcome', 'body' => 'Hello {name}']],
                ['title' => 'Notify IT', 'action' => 'webhook', 'offset_days' => 0, 'assignee_rule' => 'hr_admin', 'config' => ['url' => 'https://hooks.example.test/in']],
            ],
        ];
    }
}
