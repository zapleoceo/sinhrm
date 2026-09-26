<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Scripts\Models\Task;
use App\Modules\Workflows\Models\WorkflowRunStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\Support\WorkflowFixtures;
use Tests\TestCase;

/** Assets: authz, unique inventory numbers, assignment history, profile access, the collect_assets step. Synthetic. */
final class AssetsApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase, WorkflowFixtures;

    public function test_inventory_is_admin_only_and_numbers_are_unique(): void
    {
        $this->getJson('/api/assets')->assertUnauthorized();
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($this->login())->getJson('/api/assets')->assertForbidden();
        $this->actingAs($this->login())->postJson('/api/assets', ['inventory_number' => 'INV-1', 'name' => 'x'])->assertForbidden();

        $type = $this->actingAs($admin)->postJson('/api/assets/types', ['name' => 'Laptop'])->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson('/api/assets/types', ['name' => 'Laptop'])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'INV-001', 'name' => 'Synthetic laptop', 'type_id' => $type, 'cost' => 1200.5, 'serial' => 'SN-SYN-1'])
            ->assertCreated()->assertJsonPath('data.status', 'in_stock')->assertJsonPath('data.cost', '1200.50');
        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'inv-001', 'name' => 'Duplicate'])
            ->assertUnprocessable()->assertJsonPath('code', 'inventory_number_taken');
        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'INV-002', 'name' => 'x', 'status' => 'assigned'])->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/assets?q=syn')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_assign_return_keeps_history_and_profile_access_follows_people(): void
    {
        $admin = $this->login(UserRole::Admin);
        $org = $this->org();
        $asset = $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'INV-010', 'name' => 'Phone'])->json('data.id');

        $this->actingAs($admin)->postJson("/api/assets/$asset/return", [])->assertStatus(409)->assertJsonPath('code', 'not_assigned');
        $this->actingAs($admin)->postJson("/api/assets/$asset/assign", ['employee_id' => $org['worker']->id, 'date' => '2026-10-01', 'condition' => 'new'])->assertOk()
            ->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.employee.id', $org['worker']->id);
        $this->actingAs($admin)->postJson("/api/assets/$asset/assign", ['employee_id' => $org['peer']->id])->assertStatus(409)->assertJsonPath('code', 'not_in_stock');
        $this->actingAs($admin)->patchJson("/api/assets/$asset", ['status' => 'repair'])->assertUnprocessable()->assertJsonPath('code', 'status_via_assign');
        $this->actingAs($admin)->postJson("/api/assets/$asset/return", ['date' => '2026-09-01'])->assertUnprocessable()->assertJsonPath('code', 'return_before_assign');
        $this->actingAs($admin)->postJson("/api/assets/$asset/return", ['date' => '2026-10-20', 'condition' => 'scratched', 'status' => 'repair'])->assertOk()
            ->assertJsonPath('data.status', 'repair')->assertJsonPath('data.employee', null)
            ->assertJsonPath('data.history.0.returned_at', '2026-10-20')->assertJsonPath('data.history.0.condition_in', 'scratched');
        $this->actingAs($admin)->patchJson("/api/assets/$asset", ['status' => 'in_stock'])->assertOk();
        $this->actingAs($admin)->postJson("/api/assets/$asset/assign", ['employee_id' => $org['peer']->id, 'date' => '2026-10-21'])->assertOk();
        $this->assertSame(2, AssetAssignment::query()->where('asset_id', $asset)->count());

        // Profile tab: the employee, their managers and admins; a colleague and an unrelated person get 404.
        $url = '/api/assets/employee/'.$org['worker']->id;
        $this->actingAs($this->userOf($org['worker']))->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.asset.inventory_number', 'INV-010');
        $this->actingAs($this->userOf($org['lead']))->getJson($url)->assertOk();
        $this->actingAs($this->userOf($org['head']))->getJson($url)->assertOk();
        $this->actingAs($this->userOf($org['peer']))->getJson($url)->assertNotFound();
        $this->actingAs($this->userOf($org['other']))->getJson($url)->assertNotFound();
    }

    public function test_collect_assets_step_creates_one_task_listing_held_assets(): void
    {
        $admin = $this->login(UserRole::Admin);
        $employee = $this->employee(['full_name' => 'Leaving Person']);
        $empty = $this->employee(['full_name' => 'Nothing Held']);
        foreach (['INV-100' => 'Laptop', 'INV-101' => 'Badge'] as $number => $name) {
            $id = $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => $number, 'name' => $name])->json('data.id');
            $this->actingAs($admin)->postJson("/api/assets/$id/assign", ['employee_id' => $employee->id])->assertOk();
        }
        $template = $this->workflow([['collect_assets', 0, 'hr_admin', ['title' => 'Collect']]], ['kind' => 'offboarding']);
        $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $employee->id])->assertCreated();
        $this->actingAs($admin)->postJson('/api/workflows/runs', ['template_id' => $template->id, 'employee_id' => $empty->id])->assertCreated();

        $this->assertSame(2, $this->tick()['executed']);
        $this->tick();
        $task = Task::query()->sole();
        $this->assertSame('Collect: INV-100 Laptop, INV-101 Badge', $task->title);
        $this->assertSame('/people/'.$employee->id.'?tab=assets', $task->link);
        $this->assertSame($admin->id, $task->assignee_id);
        $skipped = WorkflowRunStep::query()->where('status', 'skipped')->sole();
        $this->assertSame('no_assets', $skipped->result['reason'] ?? null);

        // The action is known to the template editor validation (registered from the Assets module).
        $this->actingAs($admin)->postJson('/api/workflows/templates', [
            'name' => 'Offboarding', 'kind' => 'offboarding', 'trigger' => 'manual',
            'steps' => [['title' => 'Assets', 'action' => 'collect_assets', 'offset_days' => 0, 'assignee_rule' => 'hr_admin', 'config' => ['title' => 'Collect']]],
        ])->assertCreated();
    }
}
