<?php

declare(strict_types=1);

namespace Tests\Unit\HiringRequests;

use App\Models\User;
use App\Modules\HiringRequests\Services\HiringAccess;
use App\Modules\People\Contracts\PeopleAccess;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** HiringRequests asks People through the PeopleAccess contract: HR staff are the admins of hiring requests (no DB). */
final class HiringPeopleAccessTest extends TestCase
{
    public function test_admin_follows_people_access(): void
    {
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: true));
        $this->assertTrue($this->app->make(HiringAccess::class)->isAdmin($this->user()));

        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: false));
        $this->assertFalse($this->app->make(HiringAccess::class)->isAdmin($this->user()));
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 3;

        return $user;
    }
}
