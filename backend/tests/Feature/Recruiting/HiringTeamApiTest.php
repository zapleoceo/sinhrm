<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Contextual roles: hiring manager (per vacancy) and interviewer (per application). */
final class HiringTeamApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Branch $north;

    private Vacancy $managed;

    private Vacancy $other;

    private User $recruiter;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->north = Branch::factory()->create();
        $this->recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $this->manager = $this->userWith(UserRole::Employee, [$this->north]);
        $this->managed = $this->vacancyIn($this->north);
        $this->other = $this->vacancyIn($this->north);
    }

    public function test_recruiter_assigns_hiring_manager_as_string_id(): void
    {
        $this->actingAs($this->recruiter)
            ->patchJson("/api/vacancies/{$this->managed->id}", ['hiring_manager_id' => (string) $this->manager->id])
            ->assertOk()
            ->assertJsonPath('data.hiring_manager_id', $this->manager->id)
            ->assertJsonPath('data.hiring_manager.name', $this->manager->name);
    }

    public function test_employee_without_context_sees_nothing_even_with_a_branch(): void
    {
        $this->actingAs($this->manager)->getJson('/api/vacancies')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->manager)->getJson("/api/vacancies/{$this->managed->id}")->assertForbidden();
    }

    public function test_hiring_manager_sees_and_works_only_own_vacancy(): void
    {
        $this->managed->forceFill(['hiring_manager_id' => $this->manager->id])->save();
        $mine = $this->applied($this->managed);
        $foreign = $this->applied($this->other);

        $this->actingAs($this->manager)->getJson('/api/vacancies')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->managed->id);
        $this->actingAs($this->manager)->getJson("/api/vacancies/{$this->managed->id}/board")->assertOk()->assertJsonCount(1, 'data.applications');
        $this->actingAs($this->manager)->getJson("/api/vacancies/{$this->other->id}")->assertForbidden();
        $this->actingAs($this->manager)->getJson("/api/candidates/{$mine->candidate_id}")->assertOk();
        $this->actingAs($this->manager)->getJson("/api/candidates/{$foreign->candidate_id}")->assertForbidden();

        $this->actingAs($this->manager)->patchJson("/api/vacancies/{$this->managed->id}", ['title' => 'Renamed'])->assertOk();
        $this->actingAs($this->manager)->patchJson("/api/vacancies/{$this->other->id}", ['title' => 'Nope'])->assertForbidden();
        // The hiring manager cannot hand the vacancy over.
        $this->actingAs($this->manager)->patchJson("/api/vacancies/{$this->managed->id}", ['hiring_manager_id' => $this->recruiter->id])
            ->assertUnprocessable();

        $this->actingAs($this->manager)->postJson("/api/applications/{$mine->id}/move", ['stage_id' => (string) $this->stageAt(2)->id])->assertOk();
        $this->actingAs($this->manager)->postJson("/api/applications/{$foreign->id}/move", ['stage_id' => $this->stageAt(2)->id])->assertForbidden();
        // Creating vacancies stays with recruiting writers.
        $this->actingAs($this->manager)->postJson('/api/vacancies', ['title' => 'X', 'branch_id' => $this->north->id])->assertForbidden();
    }

    public function test_interviewers_are_assigned_and_see_only_their_application(): void
    {
        $interviewer = $this->userWith(UserRole::Employee);
        $mine = $this->applied($this->managed);
        $sibling = $this->applied($this->managed);

        $this->actingAs($this->recruiter)
            ->putJson("/api/applications/{$mine->id}/interviewers", ['user_ids' => [(string) $interviewer->id]])
            ->assertOk()
            ->assertJsonPath('data.interviewers.0.id', $interviewer->id);

        $this->actingAs($interviewer)->getJson("/api/candidates/{$mine->candidate_id}")->assertOk();
        $this->actingAs($interviewer)->getJson("/api/candidates/{$sibling->candidate_id}")->assertForbidden();
        $this->actingAs($interviewer)->getJson("/api/vacancies/{$this->managed->id}/board")->assertForbidden();
        $this->actingAs($interviewer)->postJson("/api/applications/{$mine->id}/move", ['stage_id' => $this->stageAt(2)->id])->assertForbidden();
        $this->actingAs($interviewer)->putJson("/api/applications/{$mine->id}/interviewers", ['user_ids' => []])->assertForbidden();

        $this->actingAs($this->recruiter)->putJson("/api/applications/{$mine->id}/interviewers", ['user_ids' => []])
            ->assertOk()->assertJsonCount(0, 'data.interviewers');
        $this->actingAs($interviewer)->getJson("/api/candidates/{$mine->candidate_id}")->assertForbidden();
    }

    public function test_hiring_manager_assigns_interviewers_on_own_vacancy_and_input_is_validated(): void
    {
        $this->managed->forceFill(['hiring_manager_id' => $this->manager->id])->save();
        $application = $this->applied($this->managed);
        $blocked = User::factory()->withRole(UserRole::Employee)->create(['status' => 'blocked']);

        $this->actingAs($this->manager)->putJson("/api/applications/{$application->id}/interviewers", ['user_ids' => [$this->manager->id]])
            ->assertOk();
        $this->actingAs($this->manager)->putJson("/api/applications/{$application->id}/interviewers", ['user_ids' => [$blocked->id]])
            ->assertUnprocessable();
        $this->actingAs($this->manager)->putJson("/api/applications/{$application->id}/interviewers", [])->assertUnprocessable();
        $this->assertSame(1, Application::query()->findOrFail($application->id)->interviewers()->count());
    }

    public function test_hr_manager_reads_all_branches_but_cannot_write(): void
    {
        $hr = $this->userWith(UserRole::HrManager);
        $south = $this->vacancyIn(Branch::factory()->create());

        $this->actingAs($hr)->getJson("/api/vacancies/{$south->id}")->assertOk();
        $this->actingAs($hr)->patchJson("/api/vacancies/{$south->id}", ['title' => 'No'])->assertForbidden();
    }

    public function test_assignable_users_for_writers_and_hiring_managers_only(): void
    {
        User::factory()->create(['name' => 'Zed Blocked', 'status' => 'blocked']);
        $this->recruiter->forceFill(['name' => 'Rita Recruiter'])->save();

        $this->actingAs($this->recruiter)->getJson('/api/recruiting/assignable-users?q=rita')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->recruiter->id);
        $this->actingAs($this->recruiter)->getJson('/api/recruiting/assignable-users?q=zed')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->manager)->getJson('/api/recruiting/assignable-users')->assertForbidden();

        $this->managed->forceFill(['hiring_manager_id' => $this->manager->id])->save();
        $this->actingAs($this->manager)->getJson('/api/recruiting/assignable-users')->assertOk()->assertJsonStructure(['data' => [['id', 'name']]]);
        $this->actingAs($this->recruiter)->getJson('/api/recruiting/assignable-users?q='.str_repeat('a', 101))->assertUnprocessable();
    }

    public function test_candidate_card_lists_interviewers(): void
    {
        $application = $this->applied($this->managed);
        $this->actingAs($this->recruiter)->putJson("/api/applications/{$application->id}/interviewers", ['user_ids' => [$this->manager->id]])->assertOk();

        $this->actingAs($this->recruiter)->getJson("/api/candidates/{$application->candidate_id}")
            ->assertOk()->assertJsonPath('data.applications.0.interviewers.0.id', $this->manager->id);
    }
}
