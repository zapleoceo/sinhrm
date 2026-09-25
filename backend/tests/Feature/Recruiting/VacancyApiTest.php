<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Synthetic data only: the repository is public. */
final class VacancyApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Branch $north;

    private Branch $south;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->north, $this->south] = Branch::factory()->count(2)->create()->all();
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/vacancies')->assertUnauthorized();
        $this->postJson('/api/vacancies', [])->assertUnauthorized();
        $this->getJson('/api/pipelines')->assertUnauthorized();
    }

    public function test_list_is_scoped_by_branch_and_role(): void
    {
        $this->vacancyIn($this->north);
        $this->vacancyIn($this->south);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $viewer = $this->userWith(UserRole::Viewer, [$this->south]);
        $noBranches = $this->userWith(UserRole::Recruiter);
        $admin = $this->userWith(UserRole::Admin);

        $this->actingAs($recruiter)->getJson('/api/vacancies')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.branch_id', $this->north->id);
        $this->actingAs($viewer)->getJson('/api/vacancies')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.branch_id', $this->south->id);
        $this->actingAs($noBranches)->getJson('/api/vacancies')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($admin)->getJson('/api/vacancies?perPage=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1);
    }

    public function test_filters_and_validation(): void
    {
        $this->vacancyIn($this->north)->update(['title' => 'Sales Manager']);
        $this->vacancyIn($this->north)->update(['title' => 'Tutor', 'status' => 'closed']);
        $admin = $this->userWith(UserRole::Admin);

        $this->actingAs($admin)->getJson('/api/vacancies?q=SALES')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/vacancies?status=closed')->assertOk()->assertJsonPath('data.0.title', 'Tutor');
        $this->actingAs($admin)->getJson('/api/vacancies?branch_id='.$this->north->id)->assertOk()->assertJsonCount(2, 'data');
        foreach (['perPage=0', 'perPage=abc', 'status=archived'] as $q) {
            $this->actingAs($admin)->getJson("/api/vacancies?$q")->assertUnprocessable();
        }
    }

    public function test_recruiter_creates_in_own_branch_only_and_viewer_cannot_create(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $viewer = $this->userWith(UserRole::Viewer, [$this->north]);

        $this->actingAs($recruiter)->postJson('/api/vacancies', ['title' => '  Cashier  ', 'branch_id' => $this->north->id])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Cashier')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.recruiter_id', $recruiter->id)
            ->assertJsonPath('data.pipeline_id', $this->defaultPipeline()->id)
            ->assertJsonCount(8, 'data.stages');
        $this->actingAs($recruiter)->postJson('/api/vacancies', ['title' => 'X', 'branch_id' => $this->south->id])
            ->assertForbidden()->assertJsonPath('code', 'vacancy_out_of_scope');
        $this->actingAs($viewer)->postJson('/api/vacancies', ['title' => 'X', 'branch_id' => $this->north->id])->assertForbidden();
        $this->actingAs($recruiter)->postJson('/api/vacancies', ['branch_id' => $this->north->id])->assertUnprocessable();
    }

    public function test_show_update_and_scope(): void
    {
        $vacancy = $this->vacancyIn($this->north);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $other = $this->userWith(UserRole::Recruiter, [$this->south]);
        $viewer = $this->userWith(UserRole::Viewer, [$this->north]);

        $this->actingAs($viewer)->getJson("/api/vacancies/$vacancy->id")->assertOk()->assertJsonPath('data.id', $vacancy->id);
        $this->actingAs($other)->getJson("/api/vacancies/$vacancy->id")->assertForbidden();
        $this->actingAs($viewer)->patchJson("/api/vacancies/$vacancy->id", ['title' => 'New'])->assertForbidden();
        $this->actingAs($other)->patchJson("/api/vacancies/$vacancy->id", ['title' => 'New'])->assertForbidden();

        $this->actingAs($recruiter)->patchJson("/api/vacancies/$vacancy->id", ['status' => 'closed'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertNotNull(Vacancy::query()->find($vacancy->id)?->closed_at);
        $this->actingAs($recruiter)->patchJson("/api/vacancies/$vacancy->id", ['pipeline_id' => 1])->assertUnprocessable();
        $this->actingAs($recruiter)->getJson('/api/vacancies/999999')->assertNotFound();
    }

    public function test_board_returns_stages_and_applications(): void
    {
        $vacancy = $this->vacancyIn($this->north);
        $this->applied($vacancy);
        $this->applied($vacancy);
        $viewer = $this->userWith(UserRole::Viewer, [$this->north]);

        $this->actingAs($viewer)->getJson("/api/vacancies/$vacancy->id/board")
            ->assertOk()
            ->assertJsonCount(8, 'data.vacancy.stages')
            ->assertJsonCount(2, 'data.applications')
            ->assertJsonPath('data.applications.0.stage_id', $this->stageAt(1)->id)
            ->assertJsonPath('data.applications.0.is_stale', false)
            ->assertJsonStructure(['data' => ['applications' => [['candidate' => ['id', 'full_name']]]]]);
        $this->actingAs($this->userWith(UserRole::Viewer, [$this->south]))
            ->getJson("/api/vacancies/$vacancy->id/board")->assertForbidden();
    }

    public function test_apply_candidate_to_vacancy(): void
    {
        $vacancy = $this->vacancyIn($this->north);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $candidate = Candidate::factory()->create(['owner_id' => $recruiter->id]);
        $foreign = Candidate::factory()->create();

        $this->actingAs($recruiter)->postJson("/api/vacancies/$vacancy->id/applications", ['candidate_id' => $candidate->id])
            ->assertCreated()->assertJsonPath('data.stage.position', 1)->assertJsonPath('data.status', 'active');
        $this->actingAs($recruiter)->postJson("/api/vacancies/$vacancy->id/applications", ['candidate_id' => $candidate->id])
            ->assertStatus(409)->assertJsonPath('code', 'already_applied');
        // Candidate not visible to the recruiter (no applications in their branches, not theirs).
        $this->actingAs($recruiter)->postJson("/api/vacancies/$vacancy->id/applications", ['candidate_id' => $foreign->id])
            ->assertForbidden();
        $this->actingAs($this->userWith(UserRole::Viewer, [$this->north]))
            ->postJson("/api/vacancies/$vacancy->id/applications", ['candidate_id' => $candidate->id])->assertForbidden();
    }

    public function test_blocked_user_gets_403(): void
    {
        $blocked = User::factory()->withRole(UserRole::Admin)->blocked()->create();
        $this->actingAs($blocked)->getJson('/api/vacancies')->assertForbidden();
    }
}
