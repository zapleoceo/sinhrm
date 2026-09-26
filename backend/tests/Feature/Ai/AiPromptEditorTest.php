<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Models\AiPromptVersion;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Services\AiService;
use App\Modules\Ai\Support\AiPromptRegistry;
use App\Modules\Ai\Support\AiSamples;
use App\Modules\Ai\Support\PromptOverrides;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Integrations\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\AiFixtures;
use Tests\TestCase;

/** Admin prompt editor (versions in ai_prompt_versions, rollback, capability, trial) and the detailed stats. */
final class AiPromptEditorTest extends TestCase
{
    use AiFixtures;
    use RefreshDatabase;

    private const string SCREENING_ANSWER = '{"score":80,"proof":true,"unmet":[],"summary":"Збіг","pros":["PHP"],"cons":[],"ask":["Чому Laravel?"]}';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    public function test_editor_is_superadmin_only_and_shows_the_built_in_prompt(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $this->actingAs($admin)->getJson('/api/ai/prompts/candidate_screening')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/ai/stats')->assertForbidden();

        $super = $this->superadmin();
        $this->actingAs($super)->getJson('/api/ai/prompts/candidate_screening')->assertOk()
            ->assertJsonPath('data.builtin_version', 'screening.v4')
            ->assertJsonPath('data.version', 'screening.v4')
            ->assertJsonPath('data.active_version', null)
            ->assertJsonPath('data.capability', 'chat:fast')
            ->assertJsonPath('data.versions', []);
        $data = $this->actingAs($super)->getJson('/api/ai/prompts/candidate_screening')->json('data');
        $this->assertStringStartsWith('ROLE: Recruiter assistant', $data['body']);
        $this->assertStringNotContainsString('OUTPUT:', $data['body']);
        $this->assertStringStartsWith('OUTPUT: {"score":int', $data['output']);

        $this->actingAs($super)->getJson('/api/ai/prompts/test')->assertNotFound();
        $this->actingAs($super)->getJson('/api/ai/prompts/unknown')->assertNotFound();
    }

    public function test_save_creates_an_active_version_used_by_ai_requests_then_rollback_and_builtin(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(self::SCREENING_ANSWER)]]);
        $super = $this->superadmin();
        $body = $this->builtinBody()."\n- Prefer candidates with open-source work.";

        $this->actingAs($super)->postJson('/api/ai/prompts/candidate_screening', ['body' => $body])->assertCreated()
            ->assertJsonPath('data.active_version', 'screening.v4-custom-1')
            ->assertJsonPath('data.versions.0.author', $super->name)
            ->assertJsonPath('data.versions.0.is_active', true);
        $this->actingAs($super)->getJson('/api/ai/status')->assertOk()
            ->assertJsonPath('data.prompt_versions.candidate_screening', 'screening.v4-custom-1');

        $outcome = $this->runScreening();
        $this->assertTrue($outcome);
        $system = $this->brokerSubmits[0]['messages'][0]['content'];
        $this->assertStringContainsString('- Prefer candidates with open-source work.', $system);
        $this->assertStringContainsString("\nOUTPUT: {\"score\":int", $system, 'OUTPUT stays code-owned.');
        $this->assertSame('screening.v4-custom-1', AiRequest::query()->sole()->prompt_version);

        // Second edit, then rollback to the first, then back to the built-in prompt.
        $this->actingAs($super)->postJson('/api/ai/prompts/candidate_screening', ['body' => $body."\n- Second."])->assertCreated()
            ->assertJsonPath('data.active_version', 'screening.v4-custom-2');
        $first = AiPromptVersion::query()->where('version', 'screening.v4-custom-1')->sole();
        $this->actingAs($super)->postJson("/api/ai/prompts/candidate_screening/versions/{$first->id}/activate")->assertOk()
            ->assertJsonPath('data.active_version', 'screening.v4-custom-1');
        $this->assertSame(1, AiPromptVersion::query()->where('is_active', true)->count());
        $this->assertSame($super->id, $first->fresh()?->activated_by);
        $this->actingAs($super)->postJson("/api/ai/prompts/mail_classification/versions/{$first->id}/activate")->assertNotFound();

        $this->actingAs($super)->postJson('/api/ai/prompts/candidate_screening/builtin')->assertOk()
            ->assertJsonPath('data.active_version', null)
            ->assertJsonPath('data.version', 'screening.v4');
        $this->runScreening();
        $this->assertSame('screening.v4', AiRequest::query()->orderByDesc('id')->firstOrFail()->prompt_version);
    }

    public function test_invalid_bodies_are_rejected_with_codes(): void
    {
        $super = $this->superadmin();
        $url = '/api/ai/prompts/candidate_screening';

        $this->actingAs($super)->postJson($url, ['body' => 'short'])->assertStatus(422)
            ->assertJsonValidationErrors(['body' => 'too_short']);
        $this->actingAs($super)->postJson($url, ['body' => "TASK: do things well and carefully\nRULES:\n- a rule that is long enough"])->assertStatus(422)
            ->assertJsonValidationErrors(['body' => 'missing_role']);
        $this->actingAs($super)->postJson($url, ['body' => $this->builtinBody()."\nOUTPUT: {\"x\":1}"])->assertStatus(422)
            ->assertJsonValidationErrors(['body' => 'output_not_editable']);
        $this->actingAs($super)->postJson($url, ['body' => $this->builtinBody()."\n- Today is 2026-10-20."])->assertStatus(422)
            ->assertJsonValidationErrors(['body' => 'volatile_data']);
        $this->actingAs($super)->postJson($url, ['body' => str_repeat('x', 7000)])->assertStatus(422)
            ->assertJsonValidationErrors(['body' => 'too_long']);
        $this->assertSame(0, AiPromptVersion::query()->count());
    }

    public function test_every_built_in_prompt_passes_its_own_validation(): void
    {
        $super = $this->superadmin();
        foreach (AiPurpose::editable() as $purpose) {
            $body = $this->actingAs($super)->getJson('/api/ai/prompts/'.$purpose->value)->assertOk()->json('data.body');
            $this->assertSame([], PromptOverrides::problems($body), $purpose->value);
        }
    }

    public function test_capability_is_saved_to_the_broker_settings(): void
    {
        $this->enableAi();
        $super = $this->superadmin();

        $this->actingAs($super)->putJson('/api/ai/prompts/mail_classification/capability', ['capability' => 'bogus'])->assertStatus(422);
        $this->actingAs($super)->putJson('/api/ai/prompts/mail_classification/capability', ['capability' => 'chat:smart'])->assertOk()
            ->assertJsonPath('data.capability', 'chat:smart');
        $this->assertSame('chat:smart', Integration::query()->where('key', 'ai_broker')->sole()->settings['capability_mail_classification']);
    }

    public function test_trial_runs_draft_and_active_on_the_sample_without_applying_anything(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(self::SCREENING_ANSWER)], [self::doneAnswer('{"score":30,"proof":false,"unmet":["PHP"],"summary":"Ні","pros":[],"cons":["x"],"ask":["y"]}')]]);
        $super = $this->superadmin();

        $response = $this->actingAs($super)->postJson('/api/ai/prompts/candidate_screening/trial', ['body' => $this->builtinBody()."\n- Draft rule."])->assertOk()
            ->assertJsonPath('data.draft.status', 'done')
            ->assertJsonPath('data.draft.version', 'screening.v4-draft')
            ->assertJsonPath('data.draft.data.verdict', 'fit')
            ->assertJsonPath('data.active.status', 'done')
            ->assertJsonPath('data.active.version', 'screening.v4')
            ->assertJsonPath('data.active.data.verdict', 'no');
        $this->assertNotNull($response->json('data.draft.request_id'));
        $this->assertStringContainsString('- Draft rule.', $this->brokerSubmits[0]['messages'][0]['content']);
        $this->assertStringNotContainsString('- Draft rule.', $this->brokerSubmits[1]['messages'][0]['content']);
        $rows = AiRequest::query()->orderBy('id')->get();
        $this->assertSame(['prompt_trial', 'prompt_trial'], $rows->map(fn (AiRequest $r): string => $r->purpose->value)->all());
        $this->assertSame('candidate_screening', $rows[0]->meta['purpose'] ?? null);
        $this->assertSame(0, AiPromptVersion::query()->count(), 'A trial saves nothing.');
    }

    public function test_trial_respects_gates(): void
    {
        $super = $this->superadmin();

        $this->actingAs($super)->postJson('/api/ai/prompts/candidate_screening/trial', ['body' => $this->builtinBody()])
            ->assertStatus(422)->assertJsonPath('code', 'ai_disabled');
        Http::assertNothingSent();
    }

    public function test_stats_per_purpose_for_a_period(): void
    {
        $super = $this->superadmin();
        $this->request('candidate_screening', 'done', null, '2026-10-20 10:00:00', 5, 0.01);
        $this->request('candidate_screening', 'done', null, '2026-10-20 11:00:00', 15, 0.02);
        $this->request('candidate_screening', 'failed', 'ai_timeout', '2026-10-20 11:30:00', null, 0);
        $this->request('mail_classification', 'failed', 'ai_invalid_output', '2026-10-15 09:00:00', null, 0.001);
        $this->request('mail_classification', 'done', null, '2026-09-01 09:00:00', 3, 0.5);

        $today = $this->actingAs($super)->getJson('/api/ai/stats?period=today')->assertOk()
            ->assertJsonPath('data.period', 'today')
            ->assertJsonPath('data.bucket', 'hour')
            ->assertJsonPath('data.purposes.candidate_screening.requests', 3)
            ->assertJsonPath('data.purposes.candidate_screening.success_pct', 67)
            ->assertJsonPath('data.purposes.candidate_screening.errors.ai_timeout', 1)
            ->assertJsonPath('data.purposes.candidate_screening.avg_latency_s', 10)
            ->assertJsonPath('data.purposes.candidate_screening.tokens_cached', 300)
            ->assertJsonPath('data.purposes.mail_classification', null)
            ->json('data');
        $this->assertCount(24, $today['purposes']['candidate_screening']['series']);
        $this->assertSame(1, $today['purposes']['candidate_screening']['series'][10]);
        $this->assertSame(2, $today['purposes']['candidate_screening']['series'][11]);
        $this->assertEqualsWithDelta(0.03, $today['purposes']['candidate_screening']['cost_usd'], 1e-9);

        $week = $this->actingAs($super)->getJson('/api/ai/stats?period=7d')->assertOk()
            ->assertJsonPath('data.bucket', 'day')
            ->assertJsonPath('data.purposes.mail_classification.requests', 1)
            ->assertJsonPath('data.purposes.mail_classification.success_pct', 0)
            ->json('data');
        $this->assertCount(7, $week['purposes']['candidate_screening']['series']);
        $this->actingAs($super)->getJson('/api/ai/stats?period=30d')->assertOk()
            ->assertJsonPath('data.purposes.mail_classification.requests', 1);
    }

    private function superadmin(): User
    {
        return User::factory()->withRole(UserRole::Superadmin)->create();
    }

    private function builtinBody(): string
    {
        return (string) $this->actingAs($this->superadmin())->getJson('/api/ai/prompts/candidate_screening')->json('data.builtin_body');
    }

    private function runScreening(): bool
    {
        $template = $this->app->make(AiPromptRegistry::class)->find(AiPurpose::CandidateScreening);
        $this->assertNotNull($template);

        return $this->app->make(AiService::class)->run($template->fromFixture((array) AiSamples::input(AiPurpose::CandidateScreening)))->isDone();
    }

    private function request(string $purpose, string $status, ?string $error, string $created, ?int $latency, float $cost): void
    {
        $row = AiRequest::query()->create([
            'purpose' => $purpose,
            'provider' => 'ai_broker',
            'prompt_version' => 'x.v1',
            'status' => $status,
            'error' => $error,
            'tokens_in' => 1000,
            'tokens_out' => 100,
            'tokens_cached' => 100,
            'cost_usd' => $cost,
            'completed_at' => $latency === null ? null : Carbon::parse($created)->addSeconds($latency),
        ]);
        $row->created_at = Carbon::parse($created);
        $row->save();
    }
}
