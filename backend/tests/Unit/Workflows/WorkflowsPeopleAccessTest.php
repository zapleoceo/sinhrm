<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\Workflows\Services\AssigneeResolver;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** The "HR admin" assignee is the starter when People's PeopleAccess says they are HR staff (no DB). */
final class WorkflowsPeopleAccessTest extends TestCase
{
    public function test_hr_admin_who_started_the_run_is_the_assignee(): void
    {
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: true));

        $this->assertSame(3, $this->app->make(AssigneeResolver::class)->hr($this->user()));
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 3;

        return $user;
    }
}
