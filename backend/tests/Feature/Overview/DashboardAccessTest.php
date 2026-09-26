<?php

declare(strict_types=1);

namespace Tests\Feature\Overview;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** GET /api/dashboard: every role gets 200, counters follow the branch scope per role; blocked 403. Synthetic. */
final class DashboardAccessTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Branch $mine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mine = Branch::factory()->create();
        $other = Branch::factory()->create();
        $this->applied($this->vacancyIn($this->mine), ['full_name' => 'Mine One', 'phone' => '+380670000201']);
        $this->applied($this->vacancyIn($other), ['full_name' => 'Foreign One', 'phone' => '+380670000202']);
        $this->applied($this->vacancyIn($other), ['full_name' => 'Foreign Two', 'phone' => '+380670000203']);
    }

    /** @return iterable<string, array{UserRole, bool, int}> role, has my branch, expected active */
    public static function scopes(): iterable
    {
        yield 'superadmin sees all branches' => [UserRole::Superadmin, false, 3];
        yield 'admin sees all branches' => [UserRole::Admin, false, 3];
        yield 'recruiter with branch sees it only' => [UserRole::Recruiter, true, 1];
        yield 'recruiter without branches sees zeros' => [UserRole::Recruiter, false, 0];
        yield 'viewer with branch sees it only' => [UserRole::Viewer, true, 1];
        yield 'viewer without branches sees zeros' => [UserRole::Viewer, false, 0];
    }

    #[DataProvider('scopes')]
    public function test_active_counter_respects_branch_scope(UserRole $role, bool $withBranch, int $active): void
    {
        $user = $this->userWith($role, $withBranch ? [$this->mine] : []);

        $this->actingAs($user)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.counts.active', $active)
            ->assertJsonPath('data.counts.new_today', $active)
            ->assertJsonStructure(['data' => ['counts' => ['active', 'stale', 'unmatched_inbox', 'new_today'], 'stale_days', 'stale', 'my_tasks' => ['total', 'overdue', 'items'], 'funnel', 'touches' => ['days', 'by_channel']]]);
    }

    public function test_user_without_any_role_gets_empty_scope(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.counts', ['active' => 0, 'stale' => 0, 'unmatched_inbox' => 0, 'new_today' => 0]);
    }

    public function test_guest_blocked_and_wrong_method(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->actingAs(User::factory()->blocked()->withRole(UserRole::Admin)->create())->getJson('/api/dashboard')->assertForbidden();
        $this->actingAs($this->userWith(UserRole::Admin))->postJson('/api/dashboard')->assertStatus(405);
    }
}
