<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Models\User;
use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Ai\ScreeningPromptFactory;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\CandidateScreening;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class CandidateApplicationScopeTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    public function test_shared_candidate_outputs_only_visible_applications_for_each_role(): void
    {
        $north = Branch::factory()->create();
        $south = Branch::factory()->create();
        $first = $this->applied($this->vacancyIn($north), ['full_name' => 'Synthetic shared candidate']);
        $candidate = Candidate::query()->findOrFail($first->candidate_id);
        $second = app(ApplicationService::class)->apply(null, $candidate, $this->vacancyIn($south));
        foreach ([$first, $second] as $application) {
            CandidateScreening::query()->create(['candidate_id' => $candidate->id, 'application_id' => $application->id,
                'vacancy_id' => $application->vacancy_id, 'status' => CandidateScreening::DONE, 'trigger' => 'manual',
                'score' => 80, 'summary' => 'Private synthetic rationale '.$application->id, 'prompt_version' => 'screening.v4']);
            Touchpoint::query()->create(['candidate_id' => $candidate->id, 'application_id' => $application->id,
                'channel' => 'email', 'direction' => 'in', 'occurred_at' => now(), 'body' => 'Synthetic application '.$application->id]);
            AuditEntry::query()->create(['entity_type' => 'application', 'entity_id' => $application->id,
                'action' => 'updated', 'changes' => ['stage' => ['from' => 'synthetic old', 'to' => 'synthetic new']]]);
        }
        $globalTouch = Touchpoint::query()->create(['candidate_id' => $candidate->id, 'application_id' => null,
            'channel' => 'email', 'direction' => 'in', 'occurred_at' => now(), 'body' => 'Synthetic global contact']);
        $globalAudit = AuditEntry::query()->create(['entity_type' => 'candidate', 'entity_id' => $candidate->id,
            'action' => 'updated', 'changes' => ['name' => ['from' => 'synthetic old', 'to' => 'synthetic new']]]);
        $owner = $this->userWith(UserRole::Recruiter);
        $candidate->forceFill(['owner_id' => $owner->id])->save();
        $manager = $this->userWith(UserRole::Employee);
        $first->vacancy->forceFill(['hiring_manager_id' => $manager->id])->save();
        $interviewer = $this->userWith(UserRole::Employee);
        $second->interviewers()->attach($interviewer->id);
        $materials = app(ScreeningPromptFactory::class)->input($first->id);
        $this->assertNotNull($materials);
        $this->assertStringNotContainsString('Synthetic application '.$second->id, json_encode($materials->materials, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('Synthetic application '.$first->id, json_encode($materials->materials, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('Synthetic global contact', json_encode($materials->materials, JSON_THROW_ON_ERROR));
        $cases = [
            [$this->userWith(UserRole::Admin), [$first->id, $second->id]],
            [$this->userWith(UserRole::HrManager), [$first->id, $second->id]],
            [$this->userWith(UserRole::Recruiter, [$north]), [$first->id]],
            [$this->userWith(UserRole::Viewer, [$south]), [$second->id]],
            [$manager, [$first->id]], [$interviewer, [$second->id]], [$owner, []],
        ];
        foreach ($cases as [$actor, $visible]) {
            $this->assertOutputs($actor, $candidate, $visible, $globalTouch->id, $globalAudit->id);
            if ($visible !== [$first->id, $second->id]) {
                $hidden = in_array($first->id, $visible, true) ? $second : $first;
                $this->actingAs($actor)->getJson('/api/candidates?vacancy_id='.$hidden->vacancy_id)
                    ->assertOk()->assertJsonCount(0, 'data');
                $this->actingAs($actor)->postJson('/api/applications/'.$hidden->id.'/screening')->assertForbidden();
            }
        }
        $this->actingAs($owner)->patchJson('/api/candidates/'.$candidate->id, ['full_name' => 'Synthetic edited candidate'])
            ->assertOk()->assertJsonMissingPath('data.applications');
        foreach ([$this->userWith(UserRole::Employee, [$north]), $this->userWith(UserRole::Viewer)] as $denied) {
            foreach (['', '/timeline', '/history', '/screenings'] as $suffix) {
                $this->actingAs($denied)->getJson('/api/candidates/'.$candidate->id.$suffix)->assertForbidden();
            }
            $this->actingAs($denied)->getJson('/api/candidates')->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_hidden_pending_screening_is_not_polled_or_returned(): void
    {
        $north = Branch::factory()->create();
        $south = Branch::factory()->create();
        $first = $this->applied($this->vacancyIn($north), ['full_name' => 'Synthetic shared candidate']);
        $candidate = Candidate::query()->findOrFail($first->candidate_id);
        $hidden = app(ApplicationService::class)->apply(null, $candidate, $this->vacancyIn($south));
        $request = AiRequest::query()->create([
            'purpose' => 'candidate_screening', 'subject_type' => 'screening',
            'provider' => 'ai_broker', 'capability' => 'chat:fast', 'job_id' => 'synthetic-hidden-job',
            'status' => 'pending', 'prompt_version' => 'screening.v4',
        ]);
        $screening = CandidateScreening::query()->create([
            'candidate_id' => $candidate->id, 'application_id' => $hidden->id, 'vacancy_id' => $hidden->vacancy_id,
            'status' => CandidateScreening::PENDING, 'trigger' => 'manual', 'prompt_version' => 'screening.v4',
            'ai_request_id' => $request->id,
        ]);
        $request->subject_id = $screening->id;
        $request->save();
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldNotReceive('poll');
        $this->app->instance(AiProvider::class, $provider);
        $actor = $this->userWith(UserRole::Recruiter, [$north]);

        $this->actingAs($actor)->getJson('/api/candidates/'.$candidate->id.'/screenings')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame(CandidateScreening::PENDING, $screening->fresh()->status);
        $this->assertSame('pending', $request->fresh()->status->value);
    }

    /** @param list<int> $visible */
    private function assertOutputs(User $actor, Candidate $candidate, array $visible, int $globalTouch, int $globalAudit): void
    {
        $this->actingAs($actor);
        $url = '/api/candidates/'.$candidate->id;
        foreach ([$this->getJson('/api/candidates')->assertOk()->json('data.0.applications'),
            $this->getJson($url)->assertOk()->json('data.applications')] as $applications) {
            $ids = array_column($applications, 'id');
            sort($ids);
            $expected = $visible;
            sort($expected);
            $this->assertSame($expected, $ids);
        }
        $timeline = $this->getJson($url.'/timeline')->assertOk()->assertJsonCount(count($visible) * 2 + 1, 'data')->json('data');
        $globalSeen = false;
        foreach ($timeline as $entry) {
            if ($entry['type'] === 'stage_change') {
                $this->assertContains($entry['stage_change']['application_id'], $visible);
            } else {
                $applicationId = $entry['touchpoint']['application_id'];
                if ($applicationId !== null) {
                    $this->assertContains($applicationId, $visible);
                }
                $globalSeen = $globalSeen || $entry['touchpoint']['id'] === $globalTouch;
            }
        }
        $this->assertTrue($globalSeen);
        $screenings = $this->getJson($url.'/screenings')->assertOk()->assertJsonCount(count($visible), 'data')->json('data');
        foreach ($screenings as $screening) {
            $this->assertContains($screening['application_id'], $visible);
            $this->assertSame('Private synthetic rationale '.$screening['application_id'], $screening['summary']);
        }
        $history = $this->getJson($url.'/history')->assertOk()->json('data');
        $this->assertContains($globalAudit, array_column($history, 'id'));
        foreach ($history as $entry) {
            if ($entry['entity_type'] === 'application') {
                $this->assertContains($entry['entity_id'], $visible);
            }
        }
    }
}
