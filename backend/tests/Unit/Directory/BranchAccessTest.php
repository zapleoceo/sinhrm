<?php

declare(strict_types=1);

namespace Tests\Unit\Directory;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Contracts\AccessibleBranches;
use App\Modules\Directory\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BranchAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_and_admin_are_unrestricted(): void
    {
        $access = $this->app->make(AccessibleBranches::class);
        foreach ([UserRole::Superadmin, UserRole::Admin] as $role) {
            $this->assertNull($access->for(User::factory()->withRole($role)->create()));
        }
    }

    public function test_recruiter_and_viewer_get_their_active_branches_only(): void
    {
        $access = $this->app->make(AccessibleBranches::class);
        [$a, $b] = Branch::factory()->count(2)->create()->all();
        $disabled = Branch::factory()->disabled()->create();
        Branch::factory()->create(); // not assigned

        foreach ([UserRole::Recruiter, UserRole::Viewer] as $role) {
            $user = User::factory()->withRole($role)->create();
            $user->branches()->attach([$b->id, $a->id, $disabled->id]);
            $this->assertSame([$a->id, $b->id], $access->for($user));
        }
    }

    public function test_without_branches_or_blocked_sees_nothing(): void
    {
        $access = $this->app->make(AccessibleBranches::class);
        $this->assertSame([], $access->for(User::factory()->withRole(UserRole::Recruiter)->create()));

        $blockedAdmin = User::factory()->withRole(UserRole::Admin)->blocked()->create();
        $this->assertSame([], $access->for($blockedAdmin));
    }
}
