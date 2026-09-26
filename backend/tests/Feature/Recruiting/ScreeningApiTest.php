<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Services\AiPollJob;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\CandidateScreening;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Services\AutoScreeningJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\AiFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** AI screening (tz6) in the candidate card: authz, data sent, sync/deferred result, auto job. */
final class ScreeningApiTest extends TestCase
{
    use AiFixtures;
    use RecruitingFixtures;
    use RefreshDatabase;

    private const array ANSWER = [
        'score' => 82, 'summary' => 'Досвід 4 роки, стек збігається',
        'pros' => ['Laravel 4 роки'], 'cons' => ['Англійська не підтверджена'], 'ask' => ['Який рівень англійської?'],
    ];

    private Branch $branch;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        $this->branch = Branch::factory()->create();
        $vacancy = $this->vacancyIn($this->branch);
        $vacancy->update(['title' => 'PHP-розробник', 'description' => 'Laravel від 3 років, PostgreSQL']);
        $this->application = $this->applied($vacancy, [
            'full_name' => 'Синтетик Тестович', 'phone' => '+380670000001', 'email' => 'synthetic.candidate@example.test',
        ]);
        Touchpoint::query()->create([
            'candidate_id' => $this->application->candidate_id, 'channel' => Channel::Note, 'direction' => Direction::In,
            'occurred_at' => Carbon::now(), 'body' => 'CV: Синтетик Тестович, Laravel 4 роки, тел. +380670000001, synthetic.candidate@example.test',
        ]);
    }

    public function test_manual_screening_returns_the_advisory_result_and_sends_no_contacts(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(self::ANSWER)]]);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);

        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/screening")
            ->assertCreated()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.score', 82)
            ->assertJsonPath('data.verdict', 'fit')
            ->assertJsonPath('data.advisory', true)
            ->assertJsonPath('data.prompt_version', 'screening.v4')
            ->assertJsonPath('data.questions.0', 'Який рівень англійської?');

        $user = $this->brokerSubmits[0]['messages'][1]['content'];
        foreach (['Синтетик', 'Тестович', '+380670000001', 'synthetic.candidate@example.test'] as $pii) {
            $this->assertStringNotContainsString($pii, $user);
        }
        $this->assertStringContainsString('Laravel від 3 років', $user);
        $this->assertSame(['chat:fast'], $this->brokerCapabilities);

        $this->actingAs($this->userWith(UserRole::Viewer, [$this->branch]))
            ->getJson("/api/candidates/{$this->application->candidate_id}/screenings")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.summary', 'Досвід 4 роки, стек збігається');
    }

    public function test_slow_answer_is_pending_then_completed_when_the_card_is_opened(): void
    {
        $this->enableAi();
        // 7 polls fit into the 40 s wait (backoff 2, 2, 3, 5, 8, 13, then the remaining 7 s); the 8th answers.
        $this->fakeBroker([[...array_fill(0, 7, self::pendingAnswer()), self::doneAnswer(self::ANSWER)]]);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);

        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/screening")
            ->assertStatus(202)->assertJsonPath('data.status', 'pending');
        // A second click does not start (or pay for) another screening.
        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/screening")->assertStatus(202);
        $this->assertSame(1, CandidateScreening::query()->count());

        $this->actingAs($recruiter)->getJson("/api/candidates/{$this->application->candidate_id}/screenings")
            ->assertOk()->assertJsonPath('data.0.status', 'done')->assertJsonPath('data.0.score', 82);
        $this->assertSame(0, $this->app->make(AiPollJob::class)->run(Carbon::now())['polled']);
    }

    public function test_unmet_must_have_caps_the_score_and_no_materials_means_no_call(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(['unmet' => ['Англійська C1 не підтверджена']] + self::ANSWER)]]);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);

        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/screening")->assertCreated()
            ->assertJsonPath('data.score', 69)
            ->assertJsonPath('data.verdict', 'maybe')
            ->assertJsonPath('data.gaps.0', 'Англійська C1 не підтверджена');

        $empty = $this->applied($this->application->vacancy, ['full_name' => 'Без Матеріалів']);
        $this->actingAs($recruiter)->postJson("/api/applications/{$empty->id}/screening")->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'insufficient_data');
        $this->assertCount(1, $this->brokerSubmits);
    }

    public function test_authorization_follows_the_candidate_policy(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(self::ANSWER)]]);
        $other = $this->userWith(UserRole::Recruiter, [Branch::factory()->create()]);
        $viewer = $this->userWith(UserRole::Viewer, [$this->branch]);

        $this->actingAs($other)->postJson("/api/applications/{$this->application->id}/screening")->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/applications/{$this->application->id}/screening")->assertForbidden();
        $this->actingAs($other)->getJson("/api/candidates/{$this->application->candidate_id}/screenings")->assertForbidden();
        $this->assertSame([], $this->brokerSubmits);
    }

    public function test_refusals_are_coded_and_budget_refusal_is_recorded(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/screening")
            ->assertStatus(422)->assertJsonPath('code', 'ai_disabled');
        $this->assertSame(0, CandidateScreening::query()->count());

        $this->enableAi(['max_requests_per_day' => '1']);
        AiRequest::query()->create(['purpose' => 'test', 'provider' => 'ai_broker', 'prompt_version' => 'test.v1', 'status' => 'done']);
        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/screening")
            ->assertStatus(429)->assertJsonPath('code', 'ai_budget_exceeded');
        $this->assertSame(['failed', 'ai_budget_exceeded'], [CandidateScreening::query()->value('status'), CandidateScreening::query()->value('error')]);
    }

    public function test_auto_screening_is_off_by_default_and_submits_without_waiting_when_on(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(self::ANSWER)]]);
        $job = $this->app->make(AutoScreeningJob::class);

        $this->assertSame(['skipped' => 'auto_off'], $job->run(Carbon::now()));

        $this->enableAi(['ai_screening_auto' => 'on']);
        $this->assertSame(['started' => 1], $this->app->make(AutoScreeningJob::class)->run(Carbon::now()));
        $this->assertSame(['started' => 0], $this->app->make(AutoScreeningJob::class)->run(Carbon::now()));
        $this->assertSame(0, $this->brokerPolls);

        $this->app->make(AiPollJob::class)->run(Carbon::now());
        $this->assertSame(['done', 'auto', 82], [
            CandidateScreening::query()->value('status'), CandidateScreening::query()->value('trigger'), CandidateScreening::query()->value('score'),
        ]);
    }
}
