<?php

declare(strict_types=1);

namespace Tests\Unit\SafeSpeak;

use App\Models\User;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\SafeSpeak\Services\SafeSpeakService;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** A Safe Speak handler must be HR staff by People's PeopleAccess contract and carry the handler flag (no DB). */
final class SafeSpeakPeopleAccessTest extends TestCase
{
    public function test_handler_needs_the_flag_and_people_admin(): void
    {
        $user = $this->user();
        $user->safe_speak_handler = true;
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: true));
        $this->assertTrue($this->app->make(SafeSpeakService::class)->isHandler($user));

        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: false));
        $this->assertFalse($this->app->make(SafeSpeakService::class)->isHandler($user));
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 3;

        return $user;
    }
}
