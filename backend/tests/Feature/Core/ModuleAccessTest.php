<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Models\ModuleSetting;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Knowledge\Models\KbArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** docs/modules/modules-access.md: company-wide on/off + per-role visibility, enforced on the server. */
final class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_module_declares_metadata_and_core_modules_are_marked(): void
    {
        $modules = $this->app->make(ModuleRegistry::class)->all();

        $this->assertCount(28, $modules);
        $core = array_keys(array_filter($modules, static fn ($m): bool => $m->core));
        sort($core);
        $this->assertSame(['auth', 'core', 'directory', 'integrations', 'observability', 'overview', 'users'], $core);
    }

    public function test_defaults_are_seeded_and_preserve_todays_access(): void
    {
        $this->assertSame(21, ModuleSetting::query()->count());
        $this->assertSame(['superadmin'], ModuleSetting::query()->where('module', 'audit')->value('roles'));
        $this->assertSame(['superadmin', 'admin'], ModuleSetting::query()->where('module', 'privacy')->value('roles'));
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

    public function test_privacy_routes_and_retention_job_follow_the_switch(): void
    {
        config(['ops.secret' => 'test-secret']);
        $this->saveSetting('privacy', false, ['superadmin', 'admin']);

        $this->actingAs($this->user(UserRole::Admin))->getJson('/api/privacy/settings')
            ->assertForbidden()
            ->assertJsonPath('message', 'module_disabled');
        $this->postJson('/api/ops/jobs/run', [], ['X-Ops-Secret' => 'test-secret'])->assertOk()
            ->assertJsonPath('jobs', fn (array $jobs): bool => $jobs['privacy.retention'] === ['ok' => true, 'skipped' => 'module_disabled']);
    }

    public function test_error_log_is_core_and_client_reports_stay_open_to_every_role(): void
    {
        $this->assertTrue($this->app->make(ModuleRegistry::class)->all()['observability']->core);
        $this->actingAs($this->user(UserRole::Superadmin))->putJson('/api/modules/observability', ['enabled' => false, 'roles' => []])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'module_core');

        $payload = ['kind' => 'TypeError', 'message' => 'boom', 'location' => 'chunk.js:1:1', 'route' => '/tasks'];
        $this->actingAs($this->user(UserRole::Employee))->postJson('/api/errors/client', $payload)->assertNoContent();
        $this->actingAs($this->user(UserRole::Employee))->getJson('/api/errors')->assertForbidden();
    }

    public function test_audit_routes_and_retention_job_follow_the_switch(): void
    {
        config(['ops.secret' => 'test-secret']);
        $this->saveSetting('audit', false, ['superadmin']);

        $this->actingAs($this->user(UserRole::Superadmin))->getJson('/api/audit')
            ->assertForbidden()
            ->assertJsonPath('message', 'module_disabled');
        $jobs = $this->postJson('/api/ops/jobs/run', [], ['X-Ops-Secret' => 'test-secret'])->assertOk()->json('jobs');
        $this->assertSame(['ok' => true, 'skipped' => 'module_disabled'], $jobs['audit.retention']);
    }

    public function test_settings_are_cached_and_the_cache_is_dropped_on_save(): void
    {
        $super = $this->user(UserRole::Superadmin);
        $this->actingAs($super)->getJson('/api/auth/me')->assertOk();
        $this->assertTrue(Cache::has(ModuleAccess::CACHE_KEY));

        $this->actingAs($super)->putJson('/api/modules/pulse', ['enabled' => false, 'roles' => []])->assertOk();

        // The old cached copy was dropped: the cache now holds the saved value, not the stale one.
        $this->assertFalse(Cache::get(ModuleAccess::CACHE_KEY)['pulse']['enabled']);
    }

    public function test_settings_page_is_superadmin_only(): void
    {
        $this->actingAs($this->user(UserRole::Admin))->getJson('/api/modules')->assertForbidden();

        $this->actingAs($this->user(UserRole::Superadmin))->getJson('/api/modules')
            ->assertOk()
            ->assertJsonCount(28, 'data')
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
        Cache::forget(ModuleAccess::CACHE_KEY);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->withRole($role)->create();
    }
}
