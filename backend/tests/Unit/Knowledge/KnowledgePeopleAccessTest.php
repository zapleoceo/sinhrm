<?php

declare(strict_types=1);

namespace Tests\Unit\Knowledge;

use App\Models\User;
use App\Modules\Knowledge\Services\KnowledgeService;
use App\Modules\People\Contracts\PeopleAccess;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** Knowledge editors are HR staff, asked through People's PeopleAccess contract (no DB). */
final class KnowledgePeopleAccessTest extends TestCase
{
    public function test_editor_follows_people_access(): void
    {
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: true));
        $this->assertTrue($this->app->make(KnowledgeService::class)->isEditor($this->user()));

        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: false));
        $this->assertFalse($this->app->make(KnowledgeService::class)->isEditor($this->user()));
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 3;

        return $user;
    }
}
