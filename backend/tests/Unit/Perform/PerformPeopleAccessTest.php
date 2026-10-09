<?php

declare(strict_types=1);

namespace Tests\Unit\Perform;

use App\Models\User;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\People\Models\Employee;
use App\Modules\Perform\Exceptions\PerformException;
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

    public function test_admin_flag_comes_from_people_access(): void
    {
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: true));
        $this->assertTrue($this->app->make(PerformAccess::class)->isAdmin($this->user()));

        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: false));
        $this->assertFalse($this->app->make(PerformAccess::class)->isAdmin($this->user()));
    }

    public function test_a_login_without_an_employee_card_has_no_self_and_require_self_rejects_it(): void
    {
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: true));
        $viewer = $this->app->make(PerformAccess::class)->viewer($this->user());

        $this->assertNull($viewer->selfId());
        $this->assertNull($viewer->departmentId);
        try {
            PerformAccess::requireSelf($viewer);
            $this->fail('a viewer without an employee card must be rejected');
        } catch (PerformException $e) {
            $this->assertSame('no_employee', $e->getMessage());
            $this->assertSame(422, $e->status);
        }

        $employee = new Employee(['department_id' => 5]);
        $employee->id = 77;
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(employee: $employee));
        $this->assertSame(77, PerformAccess::requireSelf($this->app->make(PerformAccess::class)->viewer($this->user())));
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 3;

        return $user;
    }
}
