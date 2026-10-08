<?php

declare(strict_types=1);

namespace Tests\Unit\Desk;

use App\Models\User;
use App\Modules\Desk\Services\DeskService;
use App\Modules\People\Contracts\PeopleAccess;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** Desk asks People "is this HR staff" through the PeopleAccess contract. */
final class DeskPeopleAccessTest extends TestCase
{
    public function test_hr_follows_people_access(): void
    {
        $user = new User;
        $user->id = 1;

        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: true));
        $this->assertTrue($this->app->make(DeskService::class)->isHr($user));

        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: false));
        $this->assertFalse($this->app->make(DeskService::class)->isHr($user));
    }
}
