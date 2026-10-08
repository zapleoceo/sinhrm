<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\RecruitingAccess;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Recruiting\Services\StalenessService;
use Tests\TestCase;

/** Other modules use RecruitingAccess (bound to RecruitingScope) and ApplicationRepository::STALE_DAYS. */
final class RecruitingAccessTest extends TestCase
{
    public function test_the_contract_is_bound_to_recruiting_scope(): void
    {
        $this->assertInstanceOf(RecruitingScope::class, $this->app->make(RecruitingAccess::class));
    }

    public function test_an_inactive_user_has_no_rights_and_an_empty_scope(): void
    {
        $user = new User;
        $user->id = 5;
        $user->status = UserStatus::Blocked;
        $access = $this->app->make(RecruitingAccess::class);

        $this->assertFalse($access->canWrite($user));
        $this->assertFalse($access->canManage($user));
        $this->assertSame([], $access->for($user)->branchIds);
    }

    public function test_one_stale_threshold_for_the_board_and_the_home_page(): void
    {
        $this->assertSame(ApplicationRepository::STALE_DAYS, StalenessService::DEFAULT_DAYS);
    }
}
