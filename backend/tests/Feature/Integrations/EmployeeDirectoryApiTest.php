<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Integrations\Contracts\EmployeeDirectoryGateway;
use App\Modules\Integrations\DTO\EmployeeDirectorySnapshot;
use App\Modules\Integrations\DTO\EmployeeDirectorySourceStatus;
use App\Modules\Integrations\Enums\EmployeeDirectoryGatewayState;
use App\Modules\Integrations\Exceptions\EmployeeDirectoryUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EmployeeDirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_source_snapshot_is_rejected_and_does_not_write_people_records(): void
    {
        $this->app->instance(EmployeeDirectoryGateway::class, new class implements EmployeeDirectoryGateway
        {
            public function status(): EmployeeDirectorySourceStatus
            {
                return new EmployeeDirectorySourceStatus(EmployeeDirectoryGatewayState::ReadyForPreview, [], 'company-a');
            }

            public function fetchCompleteSnapshot(): EmployeeDirectorySnapshot
            {
                return new EmployeeDirectorySnapshot('company-a', false, []);
            }
        });
        $admin = User::factory()->withRole(UserRole::Superadmin)->create();

        $this->actingAs($admin)->getJson('/api/integrations/itstep-directory/preview')
            ->assertUnprocessable()->assertJsonPath('code', 'snapshot_invalid')->assertJsonPath('reason', 'snapshot_incomplete');
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_pending_status_and_fetch_are_gated_to_superadmin_and_do_not_call_a_source(): void
    {
        $this->getJson('/api/integrations/itstep-directory/status')->assertUnauthorized();
        $viewer = User::factory()->withRole(UserRole::Viewer)->create();
        $this->actingAs($viewer)->getJson('/api/integrations/itstep-directory/status')->assertForbidden();

        $admin = User::factory()->withRole(UserRole::Superadmin)->create();
        $status = $this->actingAs($admin)->getJson('/api/integrations/itstep-directory/status')
            ->assertOk()->assertJsonPath('data.status', 'dependency_pending')
            ->assertJsonPath('data.scope_configured', false)->assertJsonPath('data.writes_enabled', false);
        self::assertStringNotContainsString('token', strtolower($status->getContent()));
        $this->actingAs($admin)->getJson('/api/integrations/itstep-directory/preview')
            ->assertStatus(409)->assertJsonPath('code', 'dependency_pending');
        Http::assertNothingSent();
    }

    public function test_preview_endpoint_validates_an_injected_complete_snapshot_without_writing(): void
    {
        $this->app->instance(EmployeeDirectoryGateway::class, new class implements EmployeeDirectoryGateway
        {
            public function status(): EmployeeDirectorySourceStatus
            {
                return new EmployeeDirectorySourceStatus(EmployeeDirectoryGatewayState::ReadyForPreview, [], 'company-a');
            }

            public function fetchCompleteSnapshot(): EmployeeDirectorySnapshot
            {
                return new EmployeeDirectorySnapshot('company-a', true, [[
                    'source_id' => 'profile-1', 'display_name' => 'Synthetic Person', 'branch_key' => null, 'position_key' => null, 'status_code' => 'active',
                ]]);
            }
        });
        $admin = User::factory()->withRole(UserRole::Superadmin)->create();

        $this->actingAs($admin)->getJson('/api/integrations/itstep-directory/preview')
            ->assertOk()->assertJsonPath('data.status', 'preview_only')->assertJsonPath('data.profiles.0.link_action', 'manual_identity_review');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_synthetic_preview_is_labeled_and_never_uses_the_gateway(): void
    {
        $this->app->instance(EmployeeDirectoryGateway::class, new class implements EmployeeDirectoryGateway
        {
            public function status(): EmployeeDirectorySourceStatus
            {
                return new EmployeeDirectorySourceStatus(EmployeeDirectoryGatewayState::DependencyPending, []);
            }

            public function fetchCompleteSnapshot(): EmployeeDirectorySnapshot
            {
                throw new EmployeeDirectoryUnavailable('must_not_fetch');
            }
        });
        $admin = User::factory()->withRole(UserRole::Superadmin)->create();

        $this->actingAs($admin)->getJson('/api/integrations/itstep-directory/synthetic-preview')
            ->assertOk()->assertJsonPath('synthetic', true)->assertJsonPath('data.duplicate_count', 1)
            ->assertJsonPath('data.status', 'conflicts_found')
            ->assertJsonPath('data.conflicts.0.code', 'duplicate_id_conflicting_record');
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('users', 1);
    }
}
