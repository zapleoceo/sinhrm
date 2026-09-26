<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\NavBadgeService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /api/nav/badges: signed-in users only, own counters, role-dependent keys, short per-user cache. */
final class NavBadgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/nav/badges')->assertUnauthorized();
    }

    public function test_keys_follow_the_role(): void
    {
        $employee = User::factory()->withRole(UserRole::Recruiter)->create();
        $super = User::factory()->withRole(UserRole::Superadmin)->create();

        $mine = $this->actingAs($employee)->getJson('/api/nav/badges')->assertOk()->json('data');
        $this->assertSame(0, $mine['tasks']);
        $this->assertArrayNotHasKey('mail_unknown', $mine, 'superadmin-only counter');
        $this->assertArrayNotHasKey('desk_queue', $mine, 'HR-only counter');
        $this->assertArrayNotHasKey('safe_speak', $mine, 'handlers-only counter');

        $this->app->make(NavBadgeService::class)->forget($super);
        $all = $this->actingAs($super)->getJson('/api/nav/badges')->assertOk()->json('data');
        $this->assertArrayHasKey('mail_unknown', $all);
        $this->assertArrayHasKey('desk_queue', $all);
    }

    public function test_a_role_change_is_not_hidden_by_the_cache(): void
    {
        $user = User::factory()->withRole(UserRole::Recruiter)->create();
        $this->assertArrayNotHasKey('mail_unknown', $this->actingAs($user)->getJson('/api/nav/badges')->assertOk()->json('data'));

        $user->syncRoles([UserRole::Superadmin->value]);
        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertArrayHasKey('mail_unknown', $this->actingAs($fresh)->getJson('/api/nav/badges')->assertOk()->json('data'));
    }

    public function test_counts_are_cached_per_user_for_a_short_time(): void
    {
        $calls = 0;
        $provider = new class($calls) implements NavBadgeProvider
        {
            public function __construct(private int &$calls) {}

            public function badges(User $user): array
            {
                $this->calls++;

                return ['probe' => $user->id];
            }
        };
        $service = new NavBadgeService([$provider], $this->app->make(Repository::class), $this->app->make(ModuleAccess::class), $this->app->make(ModuleRegistry::class));
        [$a, $b] = User::factory()->count(2)->create()->all();

        $this->assertSame($a->id, $service->for($a)['probe'] ?? null);
        $service->for($a);
        $this->assertSame(1, $calls, 'second call within the TTL comes from the cache');
        $this->assertSame($b->id, $service->for($b)['probe'] ?? null, 'the cache key is per user');
        $service->forget($a);
        $service->for($a);
        $this->assertSame(3, $calls);
    }
}
