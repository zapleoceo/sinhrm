<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\User;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Enums\EmployeeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Per-endpoint coverage of /api/assets/*: happy path, 422 (incl. string params), 403 per role, 404. Synthetic. */
final class AssetsEndpointsTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    /** @return iterable<string, array{UserRole}> */
    public static function nonAdminRoles(): iterable
    {
        yield 'recruiter' => [UserRole::Recruiter];
        yield 'viewer' => [UserRole::Viewer];
        yield 'employee' => [UserRole::Employee];
    }

    /** @return iterable<string, array{UserRole}> */
    public static function adminRoles(): iterable
    {
        yield 'superadmin' => [UserRole::Superadmin];
        yield 'admin' => [UserRole::Admin];
        yield 'hr_manager' => [UserRole::HrManager];
    }

    /** @param  array<string, mixed>  $attributes */
    private function asset(User $admin, array $attributes = []): int
    {
        return (int) $this->actingAs($admin)->postJson('/api/assets', $attributes + ['inventory_number' => 'INV-'.uniqid(), 'name' => 'Synthetic item'])
            ->assertCreated()->json('data.id');
    }

    #[DataProvider('nonAdminRoles')]
    public function test_every_inventory_endpoint_is_forbidden_for_non_admin_roles(UserRole $role): void
    {
        $admin = $this->login(UserRole::Admin);
        $id = $this->asset($admin);
        $type = $this->actingAs($admin)->postJson('/api/assets/types', ['name' => 'Phone'])->json('data.id');
        $employee = $this->employee();
        $user = $this->login($role);

        $this->actingAs($user)->getJson('/api/assets/types')->assertForbidden();
        $this->actingAs($user)->postJson('/api/assets/types', ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->patchJson("/api/assets/types/$type", ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->getJson('/api/assets')->assertForbidden();
        $this->actingAs($user)->postJson('/api/assets', ['inventory_number' => 'N-1', 'name' => 'x'])->assertForbidden();
        $this->actingAs($user)->getJson("/api/assets/$id")->assertForbidden();
        $this->actingAs($user)->patchJson("/api/assets/$id", ['name' => 'x'])->assertForbidden();
        $this->actingAs($user)->postJson("/api/assets/$id/assign", ['employee_id' => $employee->id])->assertForbidden();
        $this->actingAs($user)->postJson("/api/assets/$id/return", [])->assertForbidden();
        $this->assertSame('Synthetic item', Asset::query()->findOrFail($id)->name);
        $this->assertSame(0, AssetAssignment::query()->count());
    }

    #[DataProvider('adminRoles')]
    public function test_hr_staff_roles_can_manage_inventory(UserRole $role): void
    {
        $user = $this->login($role);
        $type = $this->actingAs($user)->postJson('/api/assets/types', ['name' => 'Type '.$role->value])->assertCreated()->json('data.id');
        $this->actingAs($user)->patchJson("/api/assets/types/$type", ['name' => 'Renamed '.$role->value])->assertOk();
        $id = $this->asset($user, ['type_id' => $type]);
        $this->actingAs($user)->patchJson("/api/assets/$id", ['name' => 'Edited'])->assertOk();
        $holder = $this->employee();
        $this->actingAs($user)->postJson("/api/assets/$id/assign", ['employee_id' => $holder->id])->assertOk();
        $this->actingAs($user)->postJson("/api/assets/$id/return", [])->assertOk();
        $this->actingAs($user)->getJson('/api/assets')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson("/api/assets/employee/$holder->id")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($user->id, AssetAssignment::query()->sole()->assigned_by);
    }

    public function test_guest_and_blocked_user_are_rejected(): void
    {
        $this->getJson('/api/assets/types')->assertUnauthorized();
        $this->getJson('/api/assets/employee/1')->assertUnauthorized();
        $blocked = User::factory()->blocked()->withRole(UserRole::Admin)->create();
        $this->actingAs($blocked)->getJson('/api/assets')->assertForbidden();
    }

    public function test_types_list_create_update_and_validation(): void
    {
        $admin = $this->login(UserRole::Admin);
        $laptop = $this->actingAs($admin)->postJson('/api/assets/types', ['name' => '  Laptop  '])->assertCreated()
            ->assertJsonPath('data.name', 'Laptop')->json('data.id');
        $phone = $this->actingAs($admin)->postJson('/api/assets/types', ['name' => 'Phone'])->assertCreated()->json('data.id');

        $this->actingAs($admin)->getJson('/api/assets/types')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($admin)->postJson('/api/assets/types', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson('/api/assets/types', ['name' => str_repeat('a', 121)])->assertUnprocessable();

        // Renaming to itself is fine (unique ignores own row); to a sibling's name is not.
        $this->actingAs($admin)->patchJson("/api/assets/types/$laptop", ['name' => 'Laptop'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/assets/types/$laptop", ['name' => 'Notebook'])->assertOk()->assertJsonPath('data.name', 'Notebook');
        $this->actingAs($admin)->patchJson("/api/assets/types/$laptop", ['name' => 'Phone'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($admin)->patchJson('/api/assets/types/'.($phone + 100), ['name' => 'Ghost'])->assertNotFound();
    }

    public function test_index_filters_and_accepts_string_params(): void
    {
        $admin = $this->login(UserRole::Admin);
        $type = $this->actingAs($admin)->postJson('/api/assets/types', ['name' => 'Laptop'])->json('data.id');
        $this->asset($admin, ['inventory_number' => 'L-1', 'name' => 'Laptop A', 'type_id' => $type]);
        $repair = $this->asset($admin, ['inventory_number' => 'P-1', 'name' => 'Phone B', 'serial' => 'SER-XYZ']);
        $this->actingAs($admin)->patchJson("/api/assets/$repair", ['status' => 'repair'])->assertOk();

        // Query strings always arrive as strings: "type_id=5" must be accepted and cast.
        $this->actingAs($admin)->getJson("/api/assets?type_id=$type")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.inventory_number', 'L-1');
        $this->actingAs($admin)->getJson('/api/assets?status=repair')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $repair);
        $this->actingAs($admin)->getJson('/api/assets?q=ser-xyz')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/assets?q=')->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($admin)->getJson('/api/assets?type_id=abc')->assertUnprocessable()->assertJsonValidationErrors('type_id');
        $this->actingAs($admin)->getJson('/api/assets?status=lost')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->getJson('/api/assets?q='.str_repeat('x', 101))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_store_validation(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->postJson('/api/assets', [])->assertUnprocessable()->assertJsonValidationErrors(['inventory_number', 'name']);
        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'X-1', 'name' => 'x', 'type_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('type_id');
        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'X-1', 'name' => 'x', 'cost' => -1])->assertUnprocessable()->assertJsonValidationErrors('cost');
        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'X-1', 'name' => 'x', 'cost' => 'cheap'])->assertUnprocessable()->assertJsonValidationErrors('cost');
        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'X-1', 'name' => 'x', 'purchased_at' => 'yesterday-ish'])->assertUnprocessable()->assertJsonValidationErrors('purchased_at');
        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'X-1', 'name' => 'x', 'status' => 'lost'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => str_repeat('9', 65), 'name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('inventory_number');

        // Numeric values as strings (form posts) are accepted.
        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'X-2', 'name' => 'x', 'cost' => '99.9', 'purchased_at' => '2026-01-15', 'status' => 'written_off'])
            ->assertCreated()->assertJsonPath('data.cost', '99.90')->assertJsonPath('data.status', 'written_off');
        $this->assertSame(1, Asset::query()->count());
    }

    public function test_inventory_number_is_trimmed_and_unique_on_update(): void
    {
        $admin = $this->login(UserRole::Admin);
        $first = $this->asset($admin, ['inventory_number' => '  INV-7  ', 'name' => 'First']);
        $second = $this->asset($admin, ['inventory_number' => 'INV-8', 'name' => 'Second']);
        $this->assertSame('INV-7', Asset::query()->findOrFail($first)->inventory_number);

        $this->actingAs($admin)->postJson('/api/assets', ['inventory_number' => 'inv-7 ', 'name' => 'Dup'])->assertUnprocessable()->assertJsonPath('code', 'inventory_number_taken');
        // Saving an asset with its own number is not a conflict; taking a sibling's number (other case) is.
        $this->actingAs($admin)->patchJson("/api/assets/$first", ['inventory_number' => 'INV-7', 'name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->actingAs($admin)->patchJson("/api/assets/$second", ['inventory_number' => 'Inv-7'])->assertUnprocessable()->assertJsonPath('code', 'inventory_number_taken');
        $this->assertSame('INV-8', Asset::query()->findOrFail($second)->inventory_number);
    }

    public function test_show_and_update_return_404_for_missing_asset(): void
    {
        $admin = $this->login(UserRole::Admin);
        $id = $this->asset($admin, ['inventory_number' => 'S-1', 'name' => 'Shown']);
        $this->actingAs($admin)->getJson("/api/assets/$id")->assertOk()->assertJsonPath('data.inventory_number', 'S-1')->assertJsonPath('data.history', []);

        $missing = $id + 1000;
        $this->actingAs($admin)->getJson("/api/assets/$missing")->assertNotFound();
        $this->actingAs($admin)->patchJson("/api/assets/$missing", ['name' => 'x'])->assertNotFound();
        $this->actingAs($admin)->postJson("/api/assets/$missing/assign", ['employee_id' => $this->employee()->id])->assertNotFound();
        $this->actingAs($admin)->postJson("/api/assets/$missing/return", [])->assertNotFound();
        $this->actingAs($admin)->getJson('/api/assets/abc')->assertNotFound();
    }

    public function test_assign_validation_and_terminated_employee(): void
    {
        $admin = $this->login(UserRole::Admin);
        $id = $this->asset($admin);
        $gone = $this->employee(['status' => EmployeeStatus::Terminated->value]);

        $this->actingAs($admin)->postJson("/api/assets/$id/assign", [])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->actingAs($admin)->postJson("/api/assets/$id/assign", ['employee_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->actingAs($admin)->postJson("/api/assets/$id/assign", ['employee_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->actingAs($admin)->postJson("/api/assets/$id/assign", ['employee_id' => $this->employee()->id, 'status' => 'repair'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->postJson("/api/assets/$id/assign", ['employee_id' => $this->employee()->id, 'date' => 'not-a-date'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->actingAs($admin)->postJson("/api/assets/$id/assign", ['employee_id' => $gone->id])->assertUnprocessable()->assertJsonPath('code', 'employee_terminated');
        $this->assertSame(0, AssetAssignment::query()->count());

        // employee_id as a string (form value) works.
        $holder = $this->employee();
        $this->actingAs($admin)->postJson("/api/assets/$id/assign", ['employee_id' => (string) $holder->id])->assertOk()
            ->assertJsonPath('data.employee.id', $holder->id)->assertJsonPath('data.history.0.assigned_at', now()->toDateString());
    }

    public function test_return_validation_and_default_status(): void
    {
        $admin = $this->login(UserRole::Admin);
        $id = $this->asset($admin);
        $holder = $this->employee();
        $this->actingAs($admin)->postJson("/api/assets/$id/assign", ['employee_id' => $holder->id, 'date' => '2026-01-10'])->assertOk();

        $this->actingAs($admin)->postJson("/api/assets/$id/return", ['employee_id' => $holder->id])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->actingAs($admin)->postJson("/api/assets/$id/return", ['status' => 'assigned'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->postJson("/api/assets/$id/return", ['condition' => str_repeat('c', 256)])->assertUnprocessable()->assertJsonValidationErrors('condition');

        $this->actingAs($admin)->postJson("/api/assets/$id/return", ['date' => '2026-01-10'])->assertOk()
            ->assertJsonPath('data.status', 'in_stock')->assertJsonPath('data.employee', null);
        $row = AssetAssignment::query()->sole();
        $this->assertSame('2026-01-10', $row->returned_at?->toDateString());
        $this->assertSame($admin->id, $row->returned_by);
        $this->assertSame($admin->id, $row->assigned_by);
        $this->actingAs($admin)->postJson("/api/assets/$id/return", [])->assertStatus(409)->assertJsonPath('code', 'not_assigned');
    }

    public function test_written_off_asset_cannot_be_assigned(): void
    {
        $admin = $this->login(UserRole::Admin);
        $id = $this->asset($admin, ['status' => 'written_off']);
        $this->actingAs($admin)->postJson("/api/assets/$id/assign", ['employee_id' => $this->employee()->id])->assertStatus(409)->assertJsonPath('code', 'not_in_stock');
    }

    public function test_employee_tab_lists_current_and_past_assets(): void
    {
        $admin = $this->login(UserRole::Admin);
        $holder = $this->employee([], $this->login(UserRole::Viewer));
        $old = $this->asset($admin, ['inventory_number' => 'OLD-1', 'name' => 'Old']);
        $now = $this->asset($admin, ['inventory_number' => 'NOW-1', 'name' => 'Now']);
        $this->actingAs($admin)->postJson("/api/assets/$old/assign", ['employee_id' => $holder->id, 'date' => '2026-01-01'])->assertOk();
        $this->actingAs($admin)->postJson("/api/assets/$old/return", ['date' => '2026-02-01'])->assertOk();
        $this->actingAs($admin)->postJson("/api/assets/$now/assign", ['employee_id' => $holder->id, 'date' => '2026-03-01'])->assertOk();

        $data = (array) $this->actingAs($this->userOf($holder))->getJson("/api/assets/employee/$holder->id")->assertOk()->assertJsonCount(2, 'data')->json('data');
        $numbers = array_map(static fn (array $row): string => $row['asset']['inventory_number'], $data);
        $this->assertEqualsCanonicalizing(['OLD-1', 'NOW-1'], $numbers);

        // Admin sees any employee; a non-existent employee is 404; unrelated non-admin users get 404, not 403.
        $this->actingAs($admin)->getJson("/api/assets/employee/$holder->id")->assertOk();
        $this->actingAs($admin)->getJson('/api/assets/employee/'.($holder->id + 1000))->assertNotFound();
        $this->actingAs($this->login(UserRole::Recruiter))->getJson("/api/assets/employee/$holder->id")->assertNotFound();
        $this->actingAs($this->login(UserRole::Viewer))->getJson("/api/assets/employee/$holder->id")->assertNotFound();
    }
}
