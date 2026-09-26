<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Services\AiPollJob;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Models\ScriptEvaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\AiFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\Support\ScriptFixtures;
use Tests\TestCase;

/** Script evaluation with AI on: stored as "ai" with the prompt version, deferred → rules first then upgraded, fallback. */
final class AiEvaluationTest extends TestCase
{
    use AiFixtures;
    use RecruitingFixtures;
    use RefreshDatabase;
    use ScriptFixtures;

    private Branch $branch;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        $this->branch = Branch::factory()->create();
        $this->application = $this->applied($this->vacancyIn($this->branch), ['full_name' => 'Test Candidate', 'phone' => '+380670000001']);
    }

    public function test_ai_evaluation_is_stored_with_a_server_side_score(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer($this->answer(['s1', 's3']))]]);
        $this->publishedScript(ScriptChannel::Call);

        $touchId = $this->logCall();

        $evaluation = ScriptEvaluation::query()->where('touchpoint_id', $touchId)->sole();
        $this->assertSame('ai', $evaluation->engine->value);
        $this->assertSame('script_eval.v2', $evaluation->prompt_version);
        $this->assertSame(50, $evaluation->score, 's1 (20) + s3 (30) of 100, whatever the model says');
        $this->assertNotNull($evaluation->ai_request_id);
        $this->assertSame(['missed_step', 'ai_tip'], array_column($evaluation->result['recommendations'], 'type'));
        $this->assertSame('Скажіть дату співбесіди.', $evaluation->result['recommendations'][1]['text'] ?? null);

        $system = $this->brokerSubmits[0]['messages'][0]['content'];
        $this->assertStringContainsString('{"id":"s1","title":"Greeting","req":true,"w":20', $system);
        $this->assertStringNotContainsString('Alex', $system, 'The transcript is not in the system part.');
        $this->assertStringContainsString('Alex', $this->brokerSubmits[0]['messages'][1]['content']);
    }

    public function test_same_script_version_gives_a_byte_identical_system_prompt(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer($this->answer(['s1']))]]);
        $this->publishedScript(ScriptChannel::Call);

        $this->logCall();
        $this->logCall('Hello again. Totally different talk on '.Carbon::now()->toDateTimeString());

        $this->assertCount(2, $this->brokerSubmits);
        $this->assertSame($this->brokerSubmits[0]['messages'][0]['content'], $this->brokerSubmits[1]['messages'][0]['content']);
        $this->assertNotSame($this->brokerSubmits[0]['messages'][1]['content'], $this->brokerSubmits[1]['messages'][1]['content']);
    }

    public function test_slow_answer_stores_rules_first_and_the_poll_job_upgrades_it_once(): void
    {
        $this->enableAi();
        $this->fakeBroker([[...array_fill(0, 12, self::pendingAnswer()), self::doneAnswer($this->answer(['s1', 's2', 's3', 's4']))]]);
        $this->publishedScript(ScriptChannel::Call);

        $touchId = $this->logCall();
        $evaluation = ScriptEvaluation::query()->where('touchpoint_id', $touchId)->sole();
        $this->assertSame('rules', $evaluation->engine->value);
        $this->assertSame(80, $evaluation->score);

        $job = $this->app->make(AiPollJob::class);
        for ($i = 0; $i < 10; $i++) {
            $job->run(Carbon::now());
        }
        $evaluation->refresh();
        $this->assertSame(['ai', 100], [$evaluation->engine->value, $evaluation->score]);
        $this->assertSame('done', AiRequest::query()->sole()->status->value);
        $this->assertSame(1, ScriptEvaluation::query()->count());
    }

    public function test_budget_exceeded_or_provider_error_falls_back_to_rules(): void
    {
        $this->enableAi(['max_requests_per_day' => '1']);
        $this->fakeBroker([[self::doneAnswer($this->answer(['s1']))]], submitStatus: 500);
        $this->publishedScript(ScriptChannel::Call);

        $first = $this->logCall();
        $this->assertSame('rules', ScriptEvaluation::query()->where('touchpoint_id', $first)->value('engine')?->value);
        $this->assertSame('ai_provider_http_500', AiRequest::query()->value('error'));

        $second = $this->logCall();
        $this->assertSame('rules', ScriptEvaluation::query()->where('touchpoint_id', $second)->value('engine')?->value);
        $this->assertSame(1, AiRequest::query()->count(), 'Over the cap: nothing more is sent.');
    }

    public function test_script_purpose_switched_off_uses_rules_without_http(): void
    {
        $this->enableAi(['ai_script_evaluation' => 'off']);
        $this->fakeBroker([[self::doneAnswer($this->answer(['s1']))]]);
        $script = $this->publishedScript(ScriptChannel::Call);

        $this->actingAs($this->userWith(UserRole::Admin))
            ->postJson("/api/scripts/{$script->id}/test", ['text' => $this->goodTranscript()])
            ->assertOk()->assertJsonPath('data.engine', 'rules');
        $this->assertSame([], $this->brokerSubmits);
    }

    public function test_editor_test_on_text_uses_ai_when_available(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer($this->answer(['s4']))]]);
        $script = $this->publishedScript(ScriptChannel::Call);

        $this->actingAs($this->userWith(UserRole::Admin))
            ->postJson("/api/scripts/{$script->id}/test", ['text' => $this->goodTranscript()])
            ->assertOk()->assertJsonPath('data.engine', 'ai')->assertJsonPath('data.score', 30);
        $this->assertSame(0, ScriptEvaluation::query()->count());
    }

    private function logCall(?string $body = null): int
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);

        return (int) $this->actingAs($recruiter)->postJson("/api/candidates/{$this->application->candidate_id}/touchpoints", [
            'channel' => 'call', 'body' => $body ?? $this->goodTranscript(), 'duration_sec' => 120,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  list<string>  $done
     * @return array<string, mixed>
     */
    private function answer(array $done): array
    {
        return [
            'steps' => array_map(static fn (string $id): array => [
                'id' => $id, 'done' => in_array($id, $done, true), 'quote' => in_array($id, $done, true) ? 'Hello' : null, 'note' => 'Коментар.',
            ], ['s1', 's2', 's3', 's4']),
            'handled' => [],
            'next' => true,
            'next_quote' => 'see you tomorrow at 10',
            'tips' => ['Скажіть дату співбесіди.'],
            'score' => 0,
        ];
    }
}
