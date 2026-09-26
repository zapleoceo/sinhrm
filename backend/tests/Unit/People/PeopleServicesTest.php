<?php

declare(strict_types=1);

namespace Tests\Unit\People;

use App\Models\User;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Employee;
use App\Modules\People\Models\EmployeeChangeRequest;
use App\Modules\People\Services\ChangeRequestService;
use App\Modules\People\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** EmployeeService (org chart) and ChangeRequestService (whitelist at apply time) — synthetic data. */
final class PeopleServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_chart_treats_a_filtered_out_manager_as_root(): void
    {
        $head = Employee::factory()->terminated()->create(['full_name' => 'Gone Head']);
        $a = Employee::factory()->create(['full_name' => 'A', 'manager_id' => $head->id]);
        Employee::factory()->create(['full_name' => 'B', 'manager_id' => $a->id]);

        $tree = $this->app->make(EmployeeService::class)->orgChart(null, null);

        $this->assertCount(1, $tree);
        $this->assertSame('A', $tree[0]['full_name']);
        $this->assertSame('B', $tree[0]['reports'][0]['full_name']);
    }

    public function test_approval_applies_only_whitelisted_keys_even_from_stored_json(): void
    {
        $actor = User::factory()->create();
        $employee = Employee::factory()->create(['hired_at' => '2025-01-15']);
        $request = EmployeeChangeRequest::query()->create([
            'employee_id' => $employee->id,
            'changes' => ['address' => 'New street 5', 'hired_at' => '2000-01-01', 'user_id' => $actor->id],
        ]);

        $this->app->make(ChangeRequestService::class)->decide($actor, new PeopleContext($actor->id, true, null, []), $request, true, null);

        $employee->refresh();
        $this->assertSame('New street 5', $employee->address);
        $this->assertSame('2025-01-15', $employee->hired_at->toDateString());
        $this->assertNull($employee->user_id);
    }

    public function test_decide_requires_rights(): void
    {
        $actor = User::factory()->create();
        $request = EmployeeChangeRequest::query()->create(['employee_id' => Employee::factory()->create()->id, 'changes' => ['phone' => '1']]);

        $this->expectException(PeopleException::class);
        $this->app->make(ChangeRequestService::class)->decide($actor, new PeopleContext($actor->id, false, null, []), $request, true, null);
    }
}
