<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Modules\Ai\DTO\AiJobRef;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Services\OpenRouterProvider;
use App\Modules\Integrations\Contracts\HostResolver;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

/** The alternative (synchronous) provider: request shape, pinned connection, answer parsing, coded failures. */
final class OpenRouterProviderTest extends TestCase
{
    use RefreshDatabase;

    private const string API_KEY = 'test-openrouter-key';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new FakeHostResolver);
    }

    public function test_successful_completion_returns_text_usage_and_pins_the_connection(): void
    {
        $this->withKey();
        Integration::query()->updateOrCreate(['key' => 'ai_broker'], ['settings' => ['model' => 'openrouter/synthetic/model-x']]);
        $options = [];
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response([
                'id' => 'gen-synthetic-1',
                'model' => 'synthetic/model-x',
                'choices' => [['message' => ['role' => 'assistant', 'content' => '{"ok":true}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'prompt_tokens_details' => ['cached_tokens' => 64], 'cost' => 0.0012],
            ]);
        });

        $job = $this->provider()->submit($this->prompt());

        $this->assertSame('openrouter', $job->provider);
        $this->assertSame('gen-synthetic-1', $job->jobId);
        $this->assertSame(0, $job->pollAfterSeconds);
        $result = $this->provider()->poll($job);
        $this->assertSame($job->immediate, $result);
        $this->assertTrue($result->isDone());
        $this->assertSame('{"ok":true}', $result->text);
        $this->assertSame('synthetic/model-x', $result->model);
        $this->assertSame([120, 30, 64], [$result->tokensIn, $result->tokensOut, $result->tokensCached]);
        $this->assertEqualsWithDelta(0.0012, $result->costUsd, 1e-9);
        $this->assertSame('stop', $result->finishReason);
        $this->assertSame([], $result->toolCalls);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r): bool {
            $body = $r->data();

            return $r->method() === 'POST'
                && $r->url() === OpenRouterProvider::URL
                && $r->header('Authorization') === ['Bearer '.self::API_KEY]
                && $body['model'] === 'synthetic/model-x'
                && $body['messages'] === [['role' => 'system', 'content' => 'Stable system part'], ['role' => 'user', 'content' => 'Synthetic question']]
                && $body['max_tokens'] === 700
                && abs($body['temperature'] - 0.2) < 1e-9
                && $body['usage'] === ['include' => true]
                && $body['response_format']['type'] === 'json_schema'
                && $body['response_format']['json_schema']['name'] === 'answer'
                && ! array_key_exists('tools', $body);
        });
        $this->assertSame(['openrouter.ai:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE] ?? null);
        $this->assertFalse($options['curl'][CURLOPT_FOLLOWLOCATION] ?? null);
        $this->assertFalse($options['allow_redirects'] ?? null);
    }

    public function test_default_model_tools_and_a_tool_call_answer(): void
    {
        $this->withKey();
        Http::fake([OpenRouterProvider::URL => Http::response([
            'choices' => [['message' => ['content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"q":"x"}']],
            ]], 'finish_reason' => 'tool_calls']],
        ])]);
        $tools = [['type' => 'function', 'function' => ['name' => 'lookup', 'description' => 'Synthetic tool', 'parameters' => ['type' => 'object']]]];
        $prompt = AiPrompt::conversation(AiPurpose::Test, 'test.v1', 'Stable system part', [['role' => 'user', 'content' => 'Hi']], $tools);

        $job = $this->provider()->submit($prompt);

        $this->assertSame('sync', $job->jobId);
        $result = $job->immediate;
        $this->assertNotNull($result);
        $this->assertTrue($result->isDone());
        $this->assertSame('', $result->text);
        $this->assertNull($result->model);
        $this->assertSame([['id' => 'call_1', 'name' => 'lookup', 'arguments' => '{"q":"x"}']], $result->toolCalls);
        $this->assertSame('tool_calls', $result->finishReason);
        $this->assertSame([0, 0, 0], [$result->tokensIn, $result->tokensOut, $result->tokensCached]);
        Http::assertSent(function (Request $r) use ($tools): bool {
            $body = $r->data();

            return $body['model'] === OpenRouterProvider::DEFAULT_MODEL
                && $body['tools'] === $tools
                && $body['tool_choice'] === 'auto'
                && ! array_key_exists('response_format', $body);
        });
    }

    public function test_malformed_body_is_a_coded_error_result(): void
    {
        $this->withKey();
        Http::fake([OpenRouterProvider::URL => Http::response(['unexpected' => true])]);

        $job = $this->provider()->submit($this->prompt());

        $this->assertNotNull($job->immediate);
        $this->assertSame(AiResult::ERROR, $job->immediate->status);
        $this->assertSame('ai_provider_bad_response', $job->immediate->error);
    }

    public function test_http_error_is_a_coded_exception_without_provider_text_or_key(): void
    {
        $this->withKey();
        Http::fake([OpenRouterProvider::URL => Http::response(['error' => ['message' => 'Invalid key '.self::API_KEY]], 401)]);

        $e = $this->submitFailing();

        $this->assertSame('ai_provider_http_401', $e->errorCode);
        $this->assertSame(502, $e->status);
        $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        $this->assertStringNotContainsString('Invalid key', $e->getMessage());
    }

    public function test_connection_failure_is_coded_and_does_not_leak_the_key(): void
    {
        $this->withKey();
        Http::fake(fn () => throw new ConnectionException('cURL error, Authorization: Bearer '.self::API_KEY));

        $e = $this->submitFailing();

        $this->assertSame('ai_provider_connection_failed', $e->errorCode);
        $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        $this->assertNull($e->getPrevious(), 'The transport exception (with the key) is not chained.');
    }

    public function test_missing_key_is_not_configured_and_sends_nothing(): void
    {
        Http::fake();

        $e = $this->submitFailing();

        $this->assertSame('ai_not_configured', $e->errorCode);
        $this->assertSame(422, $e->status);
        Http::assertNothingSent();
    }

    public function test_ssrf_guard_refusal_sends_nothing(): void
    {
        $this->withKey();
        $this->app->instance(HostResolver::class, new FakeHostResolver(['openrouter.ai' => ['10.0.0.1']]));
        Http::fake();

        $e = $this->submitFailing();

        $this->assertSame('ai_provider_blocked_host', $e->errorCode);
        Http::assertNothingSent();
    }

    public function test_key_and_a_job_without_an_answer_cannot_be_polled(): void
    {
        $this->assertSame('openrouter', $this->provider()->key());

        $result = $this->provider()->poll(new AiJobRef('openrouter', 'gen-lost', 0));

        $this->assertSame(AiResult::ERROR, $result->status);
        $this->assertSame('ai_provider_not_pollable', $result->error);
    }

    private function withKey(): void
    {
        $this->app->make(SecretVault::class)->put('openrouter', 'api_key', self::API_KEY);
    }

    private function provider(): OpenRouterProvider
    {
        return $this->app->make(OpenRouterProvider::class);
    }

    private function prompt(): AiPrompt
    {
        return new AiPrompt(
            purpose: AiPurpose::Test,
            version: 'test.v1',
            system: 'Stable system part',
            user: 'Synthetic question',
            maxTokens: 700,
            schema: ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false],
        );
    }

    private function submitFailing(): AiException
    {
        try {
            $this->provider()->submit($this->prompt());
        } catch (AiException $e) {
            return $e;
        }
        $this->fail('AiException expected');
    }
}
