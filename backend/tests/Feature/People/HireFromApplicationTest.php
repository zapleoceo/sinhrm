<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\People\Models\Employee;
use App\Modules\Recruiting\Models\Application;
use App\Modules\TimeOff\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Synthetic data only: the repository is public. */
final class HireFromApplicationTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Branch $branch;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->branch = Branch::factory()->create();
        $vacancy = $this->vacancyIn($this->branch);
        $vacancy->update(['position_id' => Position::factory()->create()->id, 'department_id' => Department::factory()->create()->id]);
        $this->application = $this->applied($vacancy->refresh(), [
            'full_name' => 'Hired Candidate', 'email' => 'hired.candidate@example.test', 'phone' => '+380501234567',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_requires_the_hire_stage(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);

        $this->actingAs($recruiter)->postJson('/api/applications/'.$this->application->id.'/hire')
            ->assertUnprocessable()->assertJsonPath('code', 'not_hired');
        $this->assertSame(0, Employee::query()->count());
    }

    public function test_creates_the_employee_once(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $this->actingAs($recruiter)->postJson('/api/applications/'.$this->application->id.'/move', ['stage_id' => $this->hireStage()->id])->assertOk();
        $vacancy = $this->application->vacancy()->firstOrFail();

        $id = $this->actingAs($recruiter)->postJson('/api/applications/'.$this->application->id.'/hire', ['hired_at' => '2026-10-12'])
            ->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath('data.full_name', 'Hired Candidate')
            ->assertJsonPath('data.branch.id', $this->branch->id)
            ->assertJsonPath('data.position.id', $vacancy->position_id)
            ->assertJsonPath('data.department.id', $vacancy->department_id)
            ->json('data.id');
        $employee = Employee::query()->findOrFail($id);
        $this->assertSame('2026-10-12', $employee->hired_at->toDateString());
        $this->assertSame('hired.candidate@example.test', $employee->personal_email);
        $this->assertNull($employee->work_email);
        $this->assertSame($this->application->candidate_id, $employee->candidate_id);

        // idempotent: the same employee, 200
        $this->actingAs($recruiter)->postJson('/api/applications/'.$this->application->id.'/hire')->assertOk()
            ->assertJsonPath('meta.created', false)->assertJsonPath('data.id', $id);
        $this->assertSame(1, Employee::query()->count());

        // the hire grants the prorated vacation of the year right away (Oct–Dec = 3/12 of 24)
        $grant = LedgerEntry::query()->where('employee_id', $id)->where('reason', 'accrual')->sole();
        $this->assertSame(6.0, (float) $grant->delta);
    }

    public function test_access(): void
    {
        $admin = $this->userWith(UserRole::Admin);
        $this->actingAs($admin)->postJson('/api/applications/'.$this->application->id.'/move', ['stage_id' => $this->hireStage()->id])->assertOk();

        $this->actingAs($this->userWith(UserRole::Viewer, [$this->branch]))->postJson('/api/applications/'.$this->application->id.'/hire')
            ->assertForbidden();
        $this->actingAs($this->userWith(UserRole::Recruiter, [Branch::factory()->create()]))
            ->postJson('/api/applications/'.$this->application->id.'/hire')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/applications/'.$this->application->id.'/hire', ['hired_at' => '12.10.2026'])
            ->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/applications/'.$this->application->id.'/hire')->assertCreated()
            ->assertJsonPath('data.status', 'active');
        $this->actingAs($admin)->postJson('/api/applications/999999/hire')->assertNotFound();
    }
}
