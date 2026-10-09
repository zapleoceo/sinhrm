<?php

declare(strict_types=1);

namespace Tests\Unit\TimeOff;

use App\Modules\People\Contracts\EmployeeLookup;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Exceptions\TimeOffException;
use App\Modules\TimeOff\Services\EmployeeResolver;
use Mockery\MockInterface;
use Tests\TestCase;

/** EmployeeResolver checks the People context and loads the employee through People's EmployeeLookup contract (no DB). */
final class EmployeeResolverTest extends TestCase
{
    public function test_own_employee_by_default_and_a_visible_one_by_id(): void
    {
        $self = new Employee;
        $report = new Employee;
        /** @var EmployeeLookup&MockInterface $employees */
        $employees = $this->mock(EmployeeLookup::class);
        $employees->expects('find')->with(10)->andReturn($self);
        $employees->expects('find')->with(11)->andReturn($report);
        $ctx = new PeopleContext(1, false, 10, [11]);

        $resolver = $this->app->make(EmployeeResolver::class);

        $this->assertSame($self, $resolver->resolve($ctx, null));
        $this->assertSame($report, $resolver->resolve($ctx, 11));
    }

    public function test_someone_outside_the_subtree_is_forbidden_without_a_lookup(): void
    {
        /** @var EmployeeLookup&MockInterface $employees */
        $employees = $this->mock(EmployeeLookup::class);
        $employees->expects('find')->never();

        $this->expectException(TimeOffException::class);
        $this->app->make(EmployeeResolver::class)->resolve(new PeopleContext(1, false, 10, [11]), 99);
    }
}
