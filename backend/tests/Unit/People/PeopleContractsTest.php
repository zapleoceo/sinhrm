<?php

declare(strict_types=1);

namespace Tests\Unit\People;

use App\Models\User;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\People\Contracts\EmployeeLookup;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\People\Services\EmployeeService;
use App\Modules\People\Services\PeopleScope;
use Tests\TestCase;

/** Other modules depend on People's contracts; People binds them to its services. */
final class PeopleContractsTest extends TestCase
{
    public function test_contracts_are_bound_to_the_people_services(): void
    {
        $this->assertInstanceOf(PeopleScope::class, $this->app->make(PeopleAccess::class));
        $this->assertInstanceOf(EmployeeService::class, $this->app->make(EmployeeLookup::class));
    }

    public function test_an_inactive_user_gets_an_empty_context_without_a_lookup(): void
    {
        $user = new User;
        $user->id = 9;
        $user->status = UserStatus::Blocked;

        $ctx = $this->app->make(PeopleAccess::class)->for($user);

        $this->assertSame([9, false, null, []], [$ctx->userId, $ctx->admin, $ctx->selfId, $ctx->subtreeIds]);
    }
}
