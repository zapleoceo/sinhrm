<?php

declare(strict_types=1);

namespace Tests\Feature\Directory;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\City;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Synthetic data only (factories / made-up names): the repository is public. */
final class DirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/directory/branches')->assertUnauthorized();
        $this->postJson('/api/directory/branches', ['name' => 'X'])->assertUnauthorized();
        $this->postJson('/api/directory/import')->assertUnauthorized();
    }

    public function test_any_active_user_can_read_every_dictionary(): void
    {
        $viewer = User::factory()->withRole(UserRole::Viewer)->create();
        foreach (['branches', 'cities', 'departments', 'positions'] as $type) {
            $this->actingAs($viewer)->getJson("/api/directory/$type")->assertOk()->assertJsonPath('meta.total', 0);
        }
    }

    public function test_blocked_user_and_unknown_dictionary(): void
    {
        $blocked = User::factory()->withRole(UserRole::Admin)->blocked()->create();
        $this->actingAs($blocked)->getJson('/api/directory/branches')->assertForbidden();
        $this->actingAs($this->superadmin)->getJson('/api/directory/planets')->assertNotFound();
    }

    public function test_list_is_sorted_filtered_and_accepts_per_page_as_string(): void
    {
        Position::factory()->create(['name' => 'Zeta Analyst']);
        Position::factory()->create(['name' => 'Alpha Tester']);
        Position::factory()->disabled()->create(['name' => 'Beta Analyst']);

        $this->actingAs($this->superadmin)->getJson('/api/directory/positions?perPage=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'Alpha Tester');
        $this->actingAs($this->superadmin)->getJson('/api/directory/positions?q=ANALYST&status=active')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Zeta Analyst');
        $this->actingAs($this->superadmin)->getJson('/api/directory/positions?status=disabled')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'disabled');
    }

    public function test_per_page_and_status_are_validated(): void
    {
        foreach (['perPage=0', 'perPage=201', 'perPage=abc', 'status=deleted'] as $query) {
            $this->actingAs($this->superadmin)->getJson("/api/directory/cities?$query")->assertUnprocessable();
        }
        $this->actingAs($this->superadmin)->getJson('/api/directory/cities?perPage=200')->assertOk();
    }

    public function test_admin_creates_and_edits_items(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $city = City::factory()->create(['name' => 'Sample City']);

        $id = $this->actingAs($admin)->postJson('/api/directory/branches', ['name' => '  Branch One ', 'city_id' => $city->id])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Branch One')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.external_id', null)
            ->assertJsonPath('data.city', ['id' => $city->id, 'name' => 'Sample City'])
            ->json('data.id');

        $this->actingAs($admin)->patchJson("/api/directory/branches/$id", ['name' => 'Branch Renamed', 'city_id' => null])
            ->assertOk()->assertJsonPath('data.name', 'Branch Renamed')->assertJsonPath('data.city', null);
        $this->actingAs($admin)->patchJson("/api/directory/branches/$id", ['status' => 'disabled'])
            ->assertOk()->assertJsonPath('data.status', 'disabled')->assertJsonPath('data.name', 'Branch Renamed');
        $this->assertDatabaseHas('branches', ['id' => $id, 'status' => 'disabled']);
    }

    public function test_other_dictionaries_have_no_city(): void
    {
        $this->actingAs($this->superadmin)->postJson('/api/directory/departments', ['name' => 'Dept A'])
            ->assertCreated()->assertJsonMissingPath('data.city_id');
        $this->actingAs($this->superadmin)->postJson('/api/directory/departments', ['name' => 'Dept B', 'city_id' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('city_id');
    }

    public function test_write_validation(): void
    {
        $dept = Department::factory()->create();
        $this->actingAs($this->superadmin)->postJson('/api/directory/cities', [])->assertJsonValidationErrors('name');
        $this->actingAs($this->superadmin)->postJson('/api/directory/cities', ['name' => str_repeat('x', 256)])
            ->assertJsonValidationErrors('name');
        $this->actingAs($this->superadmin)->patchJson("/api/directory/departments/{$dept->id}", ['status' => 'gone'])
            ->assertJsonValidationErrors('status');
        $this->actingAs($this->superadmin)->postJson('/api/directory/branches', ['name' => 'B', 'city_id' => 999])
            ->assertJsonValidationErrors('city_id');
    }

    public function test_recruiter_and_viewer_cannot_write(): void
    {
        $branch = Branch::factory()->create();
        foreach ([UserRole::Recruiter, UserRole::Viewer] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->actingAs($user)->postJson('/api/directory/branches', ['name' => 'X'])->assertForbidden();
            $this->actingAs($user)->patchJson("/api/directory/branches/{$branch->id}", ['name' => 'X'])->assertForbidden();
        }
    }

    public function test_update_of_missing_item_is_404_and_ids_do_not_cross_dictionaries(): void
    {
        $city = City::factory()->create();
        $this->actingAs($this->superadmin)->patchJson('/api/directory/cities/999', ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->superadmin)->patchJson("/api/directory/positions/{$city->id}", ['name' => 'X'])->assertNotFound();
    }

    public function test_there_is_no_delete(): void
    {
        $city = City::factory()->create();
        $this->actingAs($this->superadmin)->deleteJson("/api/directory/cities/{$city->id}")->assertStatus(405);
        $this->assertDatabaseHas('cities', ['id' => $city->id]);
    }
}
