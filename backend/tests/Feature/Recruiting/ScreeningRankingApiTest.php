<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Ai\Models\AiRequest;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\CandidateScreening;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Saved scores are advisory; list and board reads neither screen nor poll candidates. */
final class ScreeningRankingApiTest extends TestCase
{
    use AiFixtures;
    use RecruitingFixtures;
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-04 12:00:00');
        Http::preventStrayRequests();
        $this->branch = Branch::factory()->create();
        $this->actingAs($this->userWith(UserRole::Viewer, [$this->branch]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_latest_attempt_controls_scores_and_zero_precedes_unscored_candidates(): void
    {
        $vacancy = $this->vacancyIn($this->branch);
        $done = $this->applied($vacancy);
        $zero = $this->applied($vacancy);
        $pending = $this->applied($vacancy);
        $failed = $this->applied($vacancy);
        $missing = $this->applied($vacancy);
        $null = $this->applied($vacancy);
        $this->screening($done, 99);
        $this->screening($done, 82);
        $this->screening($zero, 0);
        $this->screening($pending, 100);
        $this->screening($pending, null, CandidateScreening::PENDING);
        $this->screening($failed, 95);
        $this->screening($failed, null, CandidateScreening::FAILED);
        $this->screening($null, null);

        $response = $this->getJson('/api/candidates?sort=screening_score')->assertOk();
        $this->assertSame([$done->candidate_id, $zero->candidate_id, $null->candidate_id, $missing->candidate_id, $failed->candidate_id, $pending->candidate_id], array_column($response->json('data'), 'id'));
        $this->assertSame([82, 0, null, null, null, null], array_column($response->json('data'), 'screening_score'));

        // Default board ordering remains intact: the client opts into ranking within each lane.
        $board = $this->getJson("/api/vacancies/$vacancy->id/board")->assertOk();
        $this->assertSame([$null->id, $missing->id, $failed->id, $pending->id, $zero->id, $done->id], array_column($board->json('data.applications'), 'id'));
        $this->assertSame([null, null, null, null, 0, 82], array_column($board->json('data.applications'), 'screening_score'));
    }

    public function test_ties_keep_default_updated_at_then_id_order_and_sort_precedes_pagination(): void
    {
        $vacancy = $this->vacancyIn($this->branch);
        $first = $this->applied($vacancy);
        $second = $this->applied($vacancy);
        $third = $this->applied($vacancy);
        $newestUnscored = $this->applied($vacancy);
        $first->candidate->update(['updated_at' => now()->subMinute()]);
        $this->screening($first, 80);
        $this->screening($second, 80);
        $this->screening($third, 80);

        $this->getJson('/api/candidates')->assertOk()->assertJsonPath('data.0.id', $newestUnscored->candidate_id);
        $this->getJson('/api/candidates?sort=screening_score&perPage=2')->assertOk()
            ->assertJsonPath('meta.total', 4)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $third->candidate_id)->assertJsonPath('data.1.id', $second->candidate_id);
        $this->getJson('/api/candidates?sort=screening_score&perPage=2&page=2')->assertOk()
            ->assertJsonPath('data.0.id', $first->candidate_id)->assertJsonPath('data.1.id', $newestUnscored->candidate_id);
    }

    public function test_candidate_score_is_the_maximum_of_latest_active_application_scores(): void
    {
        $first = $this->applied($this->vacancyIn($this->branch));
        $second = $this->app->make(ApplicationService::class)->apply(null, $first->candidate, $this->vacancyIn($this->branch));
        $closed = $this->app->make(ApplicationService::class)->apply(null, $first->candidate, $this->vacancyIn($this->branch));
        $closed->update(['status' => 'hired', 'stage_id' => $this->hireStage()->id]);
        $this->screening($first, 70);
        $this->screening($second, 90);
        $this->screening($closed, 100);

        $this->getJson('/api/candidates?sort=screening_score')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.screening_score', 90);
        $this->getJson('/api/vacancies/'.$first->vacancy_id.'/board')->assertOk()->assertJsonPath('data.applications.0.screening_score', 70);
        $this->screening($second, null, CandidateScreening::PENDING);
        $this->getJson('/api/candidates?sort=screening_score')->assertOk()->assertJsonPath('data.0.screening_score', 70);
    }

    public function test_vacancy_stage_and_status_filters_limit_the_applications_used_for_scoring(): void
    {
        $vacancy = $this->vacancyIn($this->branch);
        $first = $this->applied($vacancy);
        $second = $this->app->make(ApplicationService::class)->apply(null, $first->candidate, $this->vacancyIn($this->branch));
        $second->update(['stage_id' => $this->stageAt(2)->id]);
        $closed = $this->app->make(ApplicationService::class)->apply(null, $first->candidate, $this->vacancyIn($this->branch));
        $closed->update(['status' => 'rejected', 'stage_id' => $this->rejectStage()->id]);
        $this->screening($first, 40);
        $this->screening($second, 80);
        $this->screening($closed, 95);

        $this->getJson('/api/candidates?sort=screening_score&vacancy_id='.$vacancy->id)->assertOk()->assertJsonPath('data.0.screening_score', 40);
        $this->getJson('/api/candidates?sort=screening_score&stage_id='.$second->stage_id)->assertOk()->assertJsonPath('data.0.screening_score', 80);
        $this->getJson('/api/candidates?sort=screening_score&status=rejected')->assertOk()->assertJsonPath('data.0.screening_score', 95);
        $this->getJson('/api/candidates?sort=screening_score&vacancy_id='.$vacancy->id.'&stage_id='.$second->stage_id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/candidates?sort=screening_score&status=hired')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hidden_applications_do_not_change_visible_candidate_score_filters_or_order(): void
    {
        $visibleVacancy = $this->vacancyIn($this->branch);
        $hiddenVacancy = $this->vacancyIn(Branch::factory()->create());
        $visible = $this->applied($visibleVacancy);
        $hidden = $this->app->make(ApplicationService::class)->apply(null, $visible->candidate, $hiddenVacancy);
        $hidden->update(['status' => 'rejected', 'stage_id' => $this->rejectStage()->id]);
        $other = $this->applied($visibleVacancy);
        $this->screening($visible, 20);
        $this->screening($hidden, 99);
        $this->screening($other, 40);

        $response = $this->getJson('/api/candidates?sort=screening_score')->assertOk();
        $this->assertSame([$other->candidate_id, $visible->candidate_id], array_column($response->json('data'), 'id'));
        $this->assertSame([40, 20], array_column($response->json('data'), 'screening_score'));
        $this->assertSame([$visible->id], array_column($response->json('data.1.applications'), 'id'));

        $this->getJson('/api/candidates?sort=screening_score&vacancy_id='.$hiddenVacancy->id)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/candidates?sort=screening_score&stage_id='.$this->rejectStage()->id)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/candidates?sort=screening_score&status=rejected')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/candidates?sort=screening_score&vacancy_id='.$visibleVacancy->id)
            ->assertOk()->assertJsonPath('data.0.id', $other->candidate_id)
            ->assertJsonPath('data.1.id', $visible->candidate_id)
            ->assertJsonPath('data.1.screening_score', 20);
    }

    public function test_owned_candidate_without_visible_applications_has_no_score_or_application_details(): void
    {
        $owner = $this->userWith(UserRole::Viewer);
        $hiddenApplication = $this->applied($this->vacancyIn(Branch::factory()->create()), ['owner_id' => $owner->id]);
        $this->screening($hiddenApplication, 88);

        $this->actingAs($owner)->getJson('/api/candidates?sort=screening_score')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $hiddenApplication->candidate_id)
            ->assertJsonPath('data.0.screening_score', null)
            ->assertJsonCount(0, 'data.0.applications');
    }

    public function test_branch_manager_interviewer_and_admin_scores_use_their_visible_applications(): void
    {
        $visibleVacancy = $this->vacancyIn($this->branch);
        $hiddenVacancy = $this->vacancyIn(Branch::factory()->create());
        $manager = $this->userWith(UserRole::Employee);
        $interviewer = $this->userWith(UserRole::Employee);
        $visibleVacancy->update(['hiring_manager_id' => $manager->id]);
        $visible = $this->applied($visibleVacancy);
        $hidden = $this->app->make(ApplicationService::class)->apply(null, $visible->candidate, $hiddenVacancy);
        $visible->interviewers()->sync([$interviewer->id => ['created_at' => now()]]);
        $this->screening($visible, 25);
        $this->screening($hidden, 95);

        $this->getJson('/api/candidates?sort=screening_score')->assertOk()
            ->assertJsonPath('data.0.screening_score', 25);
        $this->actingAs($manager)->getJson('/api/candidates?sort=screening_score')->assertOk()
            ->assertJsonPath('data.0.screening_score', 25)
            ->assertJsonCount(1, 'data.0.applications');
        $this->actingAs($interviewer)->getJson('/api/candidates?sort=screening_score')->assertOk()
            ->assertJsonPath('data.0.screening_score', 25)
            ->assertJsonCount(1, 'data.0.applications');
        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/candidates?sort=screening_score')->assertOk()
            ->assertJsonPath('data.0.screening_score', 95)
            ->assertJsonCount(2, 'data.0.applications');
    }

    public function test_ranking_retains_scope_authentication_and_sort_validation(): void
    {
        $own = $this->applied($this->vacancyIn($this->branch));
        $foreign = $this->applied($this->vacancyIn(Branch::factory()->create()));
        $this->screening($own, 10);
        $this->screening($foreign, 100);

        $this->getJson('/api/candidates?sort=screening_score')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->candidate_id);
        $this->getJson('/api/candidates?sort=screening_score&vacancy_id='.$foreign->vacancy_id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/vacancies/'.$foreign->vacancy_id.'/board')->assertForbidden();
        $this->getJson('/api/candidates?sort=score')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/candidates?sort=screening_score')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $foreign->candidate_id);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/candidates?sort=screening_score')->assertUnauthorized();
    }

    public function test_ranking_reads_do_not_submit_or_poll_ai_or_change_applications(): void
    {
        $this->enableAi(['ai_screening_auto' => 'on']);
        $this->fakeBroker([[self::pendingAnswer()]]);
        $vacancy = $this->vacancyIn($this->branch);
        $application = $this->applied($vacancy);
        $this->screening($application, 82);
        $request = AiRequest::query()->create(['purpose' => 'candidate_screening', 'provider' => 'ai_broker', 'job_id' => '1001', 'prompt_version' => 'screening.v4', 'status' => 'pending']);
        $pending = $this->screening($application, null, CandidateScreening::PENDING);
        $pending->update(['ai_request_id' => $request->id]);
        $before = $application->fresh()->getAttributes();

        $this->getJson('/api/candidates?sort=screening_score')->assertOk()->assertJsonPath('data.0.screening_score', null);
        $this->getJson("/api/vacancies/$vacancy->id/board")->assertOk()->assertJsonPath('data.applications.0.screening_score', null);
        Http::assertNothingSent();
        $this->assertSame([], $this->brokerSubmits);
        $this->assertSame(0, $this->brokerPolls);
        $this->assertSame(2, CandidateScreening::query()->count());
        $this->assertSame(1, AiRequest::query()->count());
        $this->assertSame(CandidateScreening::PENDING, $pending->fresh()->status);
        $this->assertSame($before, $application->fresh()->getAttributes());
    }

    private function screening(Application $application, ?int $score, string $status = CandidateScreening::DONE): CandidateScreening
    {
        return CandidateScreening::query()->create([
            'application_id' => $application->id, 'candidate_id' => $application->candidate_id,
            'vacancy_id' => $application->vacancy_id, 'status' => $status, 'score' => $score,
            'trigger' => 'manual', 'prompt_version' => 'screening.v4',
            'completed_at' => $status === CandidateScreening::DONE ? now() : null,
        ]);
    }
}
