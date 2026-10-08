<?php

declare(strict_types=1);

namespace Tests\Unit\Perform;

use App\Models\User;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\People\Models\Employee;
use App\Modules\Perform\Services\PerformAccess;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** PerformAccess builds the viewer from People's PeopleAccess contract (no DB). */
final class PerformPeopleAccessTest extends TestCase
{
    public function test_viewer_takes_context_and_department_from_people_access(): void
    {
        $employee = new Employee(['department_id' => 12]);
        $employee->id = 40;
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: false, employee: $employee, subtree: [41, 42]));

        $viewer = $this->app->make(PerformAccess::class)->viewer($this->user());

        $this->assertSame(40, $viewer->ctx->selfId);
        $this->assertSame([41, 42], $viewer->ctx->subtreeIds);
        $this->assertSame(12, $viewer->departmentId);
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 3;

        return $user;
    }
}
