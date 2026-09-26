<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Prompts\TestPrompt;
use App\Modules\Ai\Services\AiPollJob;
use App\Modules\Ai\Services\AiService;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Integrations\Models\Integration;
use Carbon\CarbonInterval as Duration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\AiFixtures;
use Tests\Support\GoogleFixtures;
use Tests\TestCase;

/** AiService + AiBrokerProvider with a faked broker: gate, caps, polling, deferral, retry, logging. */
final class AiServiceTest extends TestCase
{
    use AiFixtures;
    use GoogleFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    protected array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->captureLogs();
    }

    public function test_submit_and_poll_capability_key_messages_and_usage(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::pendingAnswer(), self::doneAnswer(['ok' => true, 'reply' => 'готово'])]]);

        $outcome = $this->ai()->run(TestPrompt::build());

        $this->assertTrue($outcome->isDone());
        $this->assertSame('готово', $outcome->data['reply'] ?? null);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->url() === self::BROKER.'/v1/jobs?capability=chat%3Afast'
            && $r->header('X-Project-Key') === [self::PROJECT_KEY]);
        $body = $this->brokerSubmits[0];
        $this->assertArrayNotHasKey('model', $body, 'Empty model setting: the broker chooses (owner decision).');
        $this->assertSame(['system', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame('json_schema', $body['response_format']['type']);
        $this->assertGreaterThanOrEqual(1500, $body['max_tokens']);

        $row = AiRequest::query()->sole();
        $this->assertSame('done', $row->status->value);
        $this->assertSame('chat:fast', $row->capability);
        $this->assertSame(['1200', '300', '1024'], [(string) $row->tokens_in, (string) $row->tokens_out, (string) $row->tokens_cached]);
        $this->assertEqualsWithDelta(0.004, $row->cost_usd, 1e-9);
        $this->assertSame(TestPrompt::VERSION, $row->prompt_version);
        Sleep::assertSleptTimes(2);
    }

    public function test_capability_per_purpose_and_model_override_are_sent(): void
    {
        $this->enableAi(['capability' => 'structured', 'model' => 'synthetic/model-x']);
        $this->fakeBroker([[self::doneAnswer(['ok' => true, 'reply' => 'так'])]]);

        $this->ai()->run(TestPrompt::build());

        $this->assertSame(['structured'], $this->brokerCapabilities);
        $this->assertSame('synthetic/model-x', $this->brokerSubmits[0]['model']);
        $this->assertSame('structured', AiRequest::query()->value('capability'));
    }

    public function test_backoff_stays_within_the_wait_budget_then_defers(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::pendingAnswer()]]);
        $slept = 0;
        Sleep::whenFakingSleep(function (Duration $d) use (&$slept): void {
            $slept += (int) $d->totalSeconds;
        });

        $outcome = $this->ai()->run(TestPrompt::build(), waitSeconds: 40);

        $this->assertTrue($outcome->isDeferred());
        $this->assertSame('pending', AiRequest::query()->sole()->status->value);
        $this->assertSame('1001', AiRequest::query()->value('job_id'));
        $this->assertLessThanOrEqual(40, $slept);
        $this->assertGreaterThanOrEqual(35, $slept);
    }

    public function test_zero_wait_submits_only_and_the_poll_job_completes_once(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(['ok' => true, 'reply' => 'готово'])]]);

        $outcome = $this->ai()->run(TestPrompt::build(), waitSeconds: 0);
        $this->assertTrue($outcome->isDeferred());
        $this->assertSame(0, $this->brokerPolls);

        $first = $this->app->make(AiPollJob::class)->run(Carbon::now());
        $second = $this->app->make(AiPollJob::class)->run(Carbon::now());

        $this->assertSame(['polled' => 1, 'done' => 1, 'failed' => 0, 'pending' => 0, 'expired' => 0], $first);
        $this->assertSame(0, $second['polled']);
        $this->assertSame(1, $this->brokerPolls);
        $this->assertSame('done', AiRequest::query()->sole()->status->value);
    }

    public function test_stale_pending_requests_expire(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::pendingAnswer()]]);
        $this->ai()->run(TestPrompt::build(), waitSeconds: 0);

        $counts = $this->app->make(AiPollJob::class)->run(Carbon::now()->addHours(25));

        $this->assertSame(1, $counts['expired']);
        $this->assertSame(['failed', 'ai_timeout'], [AiRequest::query()->sole()->status->value, AiRequest::query()->value('error')]);
    }

    public function test_invalid_json_is_retried_once_then_fails(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer('Sure! Here is the answer')], [self::doneAnswer('```json {"ok": true, "reply": "ok"} ```')]]);

        $outcome = $this->ai()->run(TestPrompt::build());
        $this->assertTrue($outcome->isDone());
        $row = AiRequest::query()->sole();
        $this->assertSame(2, $row->attempts);
        $this->assertCount(2, $this->brokerSubmits);
        $this->assertEqualsWithDelta(0.008, $row->cost_usd, 1e-9, 'Both attempts are paid for.');
    }

    public function test_second_invalid_answer_fails_with_ai_invalid_output(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer('{"ok": "yes"}')]]);
        $failed = $this->ai()->run(TestPrompt::build());
        $this->assertSame('ai_invalid_output', $failed->error);
        $this->assertSame(2, AiRequest::query()->value('attempts'));
    }

    public function test_daily_request_and_cost_caps_refuse_before_sending(): void
    {
        $this->enableAi(['max_requests_per_day' => '3', 'daily_cap_usd' => '1']);
        $this->fakeBroker([[self::doneAnswer(['ok' => true, 'reply' => 'ok'])]]);
        $this->usage(attempts: 3, cost: 0.1);

        $this->assertRefused('ai_budget_exceeded', 429);

        AiRequest::query()->delete();
        $this->usage(attempts: 1, cost: 1.0);
        $this->assertRefused('ai_budget_exceeded', 429);

        // Yesterday's spending does not count.
        AiRequest::query()->update(['created_at' => Carbon::now()->subDay()]);
        $this->assertTrue($this->ai()->run(TestPrompt::build())->isDone());
    }

    public function test_policy_off_not_configured_and_purpose_off_refuse_without_http(): void
    {
        $this->fakeBroker([[self::doneAnswer(['ok' => true, 'reply' => 'ok'])]]);
        $this->assertRefused('ai_disabled', 422);

        $this->enableAi(['ai_candidate_screening' => 'off']);
        $this->assertSame('ai_purpose_disabled', $this->ai()->unavailableReason(AiPurpose::CandidateScreening));
        $this->assertNull($this->ai()->unavailableReason(AiPurpose::ScriptEvaluation));

        Integration::query()->where('key', 'ai_broker')->update(['status' => 'off']);
        $this->assertRefused('ai_not_configured', 422);
        $this->assertSame([], $this->brokerSubmits);
    }

    public function test_provider_errors_become_codes_and_the_key_never_reaches_logs_or_rows(): void
    {
        $this->enableAi();
        Http::fake([self::BROKER.'/*' => Http::response(['detail' => 'bad key '.self::PROJECT_KEY], 401)]);

        $outcome = $this->ai()->run(TestPrompt::build());

        $this->assertSame('ai_provider_http_401', $outcome->error);
        $this->assertLogsDoNotContain(self::PROJECT_KEY, TestPrompt::system());
        $this->assertStringNotContainsString(self::PROJECT_KEY, (string) json_encode(AiRequest::query()->get()->toArray()));
    }

    public function test_broker_error_job_is_stored_as_a_code(): void
    {
        $this->enableAi();
        $this->fakeBroker([[['status' => 'error', 'error' => 'daily budget cap reached — retry after 00:00 UTC']]]);
        $this->assertSame('ai_provider_budget', $this->ai()->run(TestPrompt::build())->error);
    }

    public function test_redirect_to_internal_host_is_blocked_by_the_url_guard(): void
    {
        $this->enableAi(['base_url' => 'https://10.0.0.5']);
        $this->fakeBroker([[self::doneAnswer(['ok' => true, 'reply' => 'ok'])]]);

        $this->assertSame('ai_provider_blocked_host', $this->ai()->run(TestPrompt::build())->error);
        Http::assertNothingSent();
    }

    public function test_admin_status_and_test_prompt_are_superadmin_only(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(['ok' => true, 'reply' => 'готово'])]]);
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $super = User::factory()->withRole(UserRole::Superadmin)->create();

        $this->actingAs($admin)->getJson('/api/ai/status')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/ai/test')->assertForbidden();

        $this->actingAs($super)->postJson('/api/ai/test')->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.reply', 'готово')
            ->assertJsonPath('data.tokens_cached', 1024);
        $this->actingAs($super)->getJson('/api/ai/status')->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.capabilities.script_evaluation', 'chat:fast')
            ->assertJsonPath('data.model', null)
            ->assertJsonPath('data.usage.requests', 1)
            ->assertJsonPath('data.limits.requests', 200)
            ->assertJsonPath('data.limits.cost_usd', 2);
    }

    public function test_test_prompt_refusal_is_a_coded_error(): void
    {
        $super = User::factory()->withRole(UserRole::Superadmin)->create();

        $this->actingAs($super)->postJson('/api/ai/test')->assertStatus(422)->assertJsonPath('code', 'ai_disabled');
    }

    private function ai(): AiService
    {
        return $this->app->make(AiService::class);
    }

    private function assertRefused(string $code, int $status): void
    {
        try {
            $this->ai()->run(TestPrompt::build());
            $this->fail("Expected {$code}");
        } catch (AiException $e) {
            $this->assertSame([$code, $status], [$e->errorCode, $e->status]);
        }
    }

    private function usage(int $attempts, float $cost): void
    {
        $this->app->make(AiRequestRepository::class)->create([
            'purpose' => 'test', 'provider' => 'ai_broker', 'prompt_version' => 'test.v1', 'status' => 'done',
            'attempts' => $attempts, 'cost_usd' => $cost,
        ]);
    }
}
