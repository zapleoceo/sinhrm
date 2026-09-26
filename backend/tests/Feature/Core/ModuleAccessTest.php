<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Models\ModuleSetting;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Knowledge\Models\KbArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** docs/modules/modules-access.md: company-wide on/off + per-role visibility, enforced on the server. */
final class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_module_declares_metadata_and_core_modules_are_marked(): void
    {
        $modules = $this->app->make(ModuleRegistry::class)->all();

        $this->assertCount(25, $modules);
        $core = array_keys(array_filter($modules, static fn ($m): bool => $m->core));
        sort($core);
        $this->assertSame(['auth', 'core', 'directory', 'integrations', 'overview', 'users'], $core);
    }

    public function test_defaults_are_seeded_and_preserve_todays_access(): void
    {
        $this->assertSame(19, ModuleSetting::query()->count());
        $this->assertTrue(ModuleSetting::query()->where('enabled', false)->doesntExist());
        $this->assertSame(UserRole::values(), ModuleSetting::query()->where('module', 'recruiting')->value('roles'));
        $this->assertSame(['superadmin'], ModuleSetting::query()->where('module', 'ai')->value('roles'));

        foreach (UserRole::cases() as $role) {
            $this->actingAs($this->user($role))->getJson('/api/knowledge/articles')->assertOk();
        }
    }

    public function test_disabled_module_answers_403_for_everyone_and_keeps_data(): void
    {
        $this->saveSetting('knowledge', false, UserRole::values());
        $count = KbArticle::query()->count();

        foreach ([UserRole::Superadmin, UserRole::Employee] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/knowledge/articles')
                ->assertForbidden()
                ->assertJsonPath('message', 'module_disabled');
        }
        $this->assertSame($count, KbArticle::query()->count());
        $this->assertNotContains('knowledge', $this->actingAs($this->user(UserRole::Superadmin))->getJson('/api/auth/me')->json('modules'));
    }

    public function test_anonymous_call_to_a_disabled_module_is_404(): void
    {
        $this->saveSetting('channels', false, UserRole::values());

        $this->postJson('/api/webhooks/telegram', [])->assertNotFound();
    }

    public function test_role_restriction_blocks_other_roles_but_never_superadmin(): void
    {
        $this->saveSetting('knowledge', true, ['admin']);

        $this->actingAs($this->user(UserRole::Employee))->getJson('/api/knowledge/articles')
            ->assertForbidden()
            ->assertJsonPath('message', 'module_forbidden');
        $this->actingAs($this->user(UserRole::Admin))->getJson('/api/knowledge/articles')->assertOk();
        $this->actingAs($this->user(UserRole::Superadmin))->getJson('/api/knowledge/articles')->assertOk();

        $me = $this->actingAs($this->user(UserRole::Employee))->getJson('/api/auth/me')->json('modules');
        $this->assertNotContains('knowledge', $me);
        $this->assertContains('people', $me);
    }

    public function test_role_gate_never_grants_more_than_the_module_gates(): void
    {
        // Ai stays behind manage-integrations even if an admin is allowed in the matrix.
        $this->saveSetting('ai', true, UserRole::values());

        $this->actingAs($this->user(UserRole::Admin))->getJson('/api/ai/status')->assertForbidden();
    }

    public function test_jobs_of_a_disabled_module_are_skipped(): void
    {
        config(['ops.secret' => 'test-secret']);
        $this->saveSetting('scripts', false, UserRole::values());

        $response = $this->postJson('/api/ops/jobs/run', [], ['X-Ops-Secret' => 'test-secret'])->assertOk()
            ->assertJsonPath('jobs.followups', ['ok' => true, 'skipped' => 'module_disabled']);
        // Jobs of enabled modules still run.
        $this->assertNotSame('module_disabled', $response->json('jobs')['workflows.tick']['skipped'] ?? null);
        $this->assertTrue($response->json('jobs')['workflows.tick']['ok']);
    }

    public function test_nav_badges_skip_disabled_modules(): void
    {
        $user = $this->user(UserRole::Employee);
        $this->assertArrayHasKey('desk_mine', $this->actingAs($user)->getJson('/api/nav/badges')->assertOk()->json('data'));

        $this->saveSetting('desk', false, UserRole::values());

        $this->assertArrayNotHasKey('desk_mine', $this->actingAs($user)->getJson('/api/nav/badges')->assertOk()->json('data'));
    }

    public function test_settings_page_is_superadmin_only(): void
    {
        $this->actingAs($this->user(UserRole::Admin))->getJson('/api/modules')->assertForbidden();

        $this->actingAs($this->user(UserRole::Superadmin))->getJson('/api/modules')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonFragment(['key' => 'recruiting', 'core' => false, 'enabled' => true, 'name_key' => 'modules.names.recruiting']);
    }

    public function test_superadmin_switches_a_module_and_cannot_remove_himself(): void
    {
        $this->actingAs($this->user(UserRole::Superadmin))->putJson('/api/modules/pulse', ['enabled' => false, 'roles' => ['employee']])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.roles', ['superadmin', 'employee']);

        $this->assertFalse(ModuleSetting::query()->where('module', 'pulse')->value('enabled'));
    }

    public function test_core_modules_cannot_be_switched_off(): void
    {
        $this->actingAs($this->user(UserRole::Superadmin))->putJson('/api/modules/users', ['enabled' => false, 'roles' => []])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'module_core');

        $this->actingAs($this->user(UserRole::Superadmin))->getJson('/api/modules')->assertOk();
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->actingAs($this->user(UserRole::Superadmin))->putJson('/api/modules/pulse', ['enabled' => true, 'roles' => ['boss']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles.0');
    }

    /** @param  list<string>  $roles */
    private function saveSetting(string $module, bool $enabled, array $roles): void
    {
        ModuleSetting::query()->updateOrCreate(['module' => $module], ['enabled' => $enabled, 'roles' => $roles]);
        // Settings are read once per request (scoped binding); a test reuses one app, so drop the cached copy.
        $this->app->forgetScopedInstances();
    }

    private function user(UserRole $role): User
    {
        return User::factory()->withRole($role)->create();
    }
}
