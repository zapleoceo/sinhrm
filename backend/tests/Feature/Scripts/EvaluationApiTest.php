<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Integrations\Contracts\AiPolicy;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Models\ScriptEvaluation;
use App\Modules\Scripts\Services\EvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecruitingFixtures;
use Tests\Support\ScriptFixtures;
use Tests\TestCase;

final class EvaluationApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase, ScriptFixtures;

    private Branch $branch;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->application = $this->applied($this->vacancyIn($this->branch), ['full_name' => 'Test Candidate', 'phone' => '+380670000001']);
    }

    public function test_call_transcript_is_evaluated_after_the_response_and_shown_in_the_timeline(): void
    {
        $script = $this->publishedScript(ScriptChannel::Call);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $candidateId = $this->application->candidate_id;
        $this->getJson('/api/touchpoints/1/evaluation')->assertUnauthorized();

        $touchId = $this->actingAs($recruiter)->postJson("/api/candidates/$candidateId/touchpoints", [
            'channel' => 'call', 'body' => $this->goodTranscript(), 'duration_sec' => 300,
        ])->assertCreated()->json('data.id');

        $evaluation = ScriptEvaluation::query()->where('touchpoint_id', $touchId)->firstOrFail();
        $this->assertSame(80, $evaluation->score);
        $this->assertSame($script->active_version_id, $evaluation->script_version_id);

        $this->actingAs($recruiter)->getJson("/api/candidates/$candidateId/timeline?channel=call")->assertOk()
            ->assertJsonPath('data.0.touchpoint.evaluation.score', 80)
            ->assertJsonPath('data.0.touchpoint.evaluation.engine', 'rules')
            ->assertJsonPath('data.0.touchpoint.evaluation.next_step_fixed', true);

        $this->actingAs($this->userWith(UserRole::Viewer, [$this->branch]))->getJson("/api/touchpoints/$touchId/evaluation")->assertOk()
            ->assertJsonPath('data.score', 80)
            ->assertJsonPath('data.script.id', $script->id)
            ->assertJsonPath('data.script.version', 1)
            ->assertJsonPath('data.steps.0.done', true)
            ->assertJsonPath('data.steps.0.quote', 'Hello, my name is Alex.')
            ->assertJsonPath('data.steps.1.done', false)
            ->assertJsonPath('data.next_step.quote', 'Great, see you tomorrow at 10.');

        $this->actingAs($this->userWith(UserRole::Recruiter, [Branch::factory()->create()]))
            ->getJson("/api/touchpoints/$touchId/evaluation")->assertForbidden();
    }

    public function test_only_calls_with_text_and_long_outbound_chat_messages_are_evaluated(): void
    {
        $this->publishedScript(ScriptChannel::Call);
        $this->publishedScript(ScriptChannel::Chat, null, 'Chat');
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $url = "/api/candidates/{$this->application->candidate_id}/touchpoints";
        $long = 'Hello! The position is open, let us book an interview tomorrow. '.Str::repeat('Details follow. ', 12);

        $short = $this->actingAs($recruiter)->postJson($url, ['channel' => 'telegram', 'body' => 'Hello, interview tomorrow?'])->json('data.id');
        $inbound = $this->actingAs($recruiter)->postJson($url, ['channel' => 'telegram', 'direction' => 'in', 'body' => $long])->json('data.id');
        $noText = $this->actingAs($recruiter)->postJson($url, ['channel' => 'call', 'duration_sec' => 60])->json('data.id');
        $note = $this->actingAs($recruiter)->postJson($url, ['channel' => 'note', 'body' => $long])->json('data.id');
        $chat = $this->actingAs($recruiter)->postJson($url, ['channel' => 'viber', 'body' => $long])->json('data.id');

        foreach ([$short, $inbound, $noText, $note] as $id) {
            $this->actingAs($recruiter)->getJson("/api/touchpoints/$id/evaluation")->assertNotFound()->assertJsonPath('code', 'not_evaluated');
        }
        $this->actingAs($recruiter)->getJson("/api/touchpoints/$chat/evaluation")->assertOk()->assertJsonPath('data.script.name', 'Chat');
    }

    public function test_captured_touches_are_evaluated_too_and_only_once(): void
    {
        $this->publishedScript(ScriptChannel::Call);
        $touch = $this->ingest(Channel::Call, '+380670000001', ['body' => $this->goodTranscript(), 'direction' => Direction::Out]);
        // Outside HTTP the job waits for the app to terminate; run it the way the terminating callback does.
        $service = $this->app->make(EvaluationService::class);
        $first = $service->evaluateTouchpoint($touch->id);
        $second = $service->evaluateTouchpoint($touch->id);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, ScriptEvaluation::query()->count());
    }

    public function test_no_active_script_means_no_evaluation(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $id = $this->actingAs($recruiter)->postJson("/api/candidates/{$this->application->candidate_id}/touchpoints", [
            'channel' => 'call', 'body' => $this->goodTranscript(),
        ])->assertCreated()->json('data.id');

        $this->assertSame(0, ScriptEvaluation::query()->count());
        $this->actingAs($recruiter)->getJson("/api/candidates/{$this->application->candidate_id}/timeline?channel=call")->assertOk()
            ->assertJsonPath('data.0.touchpoint.id', $id)
            ->assertJsonPath('data.0.touchpoint.evaluation', null);
    }

    public function test_ai_switched_on_still_never_calls_a_provider_and_falls_back_to_rules(): void
    {
        Http::preventStrayRequests();
        $this->app->instance(AiPolicy::class, new class implements AiPolicy
        {
            public function enabled(): bool
            {
                return true;
            }
        });
        $script = $this->publishedScript(ScriptChannel::Call);

        $this->actingAs($this->userWith(UserRole::Admin))
            ->postJson("/api/scripts/{$script->id}/test", ['text' => $this->goodTranscript()])
            ->assertOk()
            ->assertJsonPath('data.engine', 'rules')
            ->assertJsonPath('data.score', 80);
        Http::assertNothingSent();
    }
}
