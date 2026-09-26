<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\DTO\AiJobRef;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Support\AiSettingsReader;
use App\Modules\Integrations\Support\OutboundUrlGuard;
use App\Modules\Integrations\Support\SecretScrubber;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * AI Broker (https://aib.zapleo.com, docs of the broker: docs/api.md):
 *   POST {base}/v1/jobs?capability=<per purpose, default chat:fast>  X-Project-Key
 *        {messages, model? (only when set: empty = the broker chooses), max_tokens, temperature, response_format?}
 *     → 202 {job_id, poll_after_s}
 *   GET  {base}/v1/jobs/{id} → {status pending|done|error, text, model, tokens_in, tokens_out, cost_usd, finish_reason,
 *        cache_read_tokens, error}
 * The broker puts cache_control on the first system message itself (prompt caching); we keep that message stable.
 * Every call: OutboundUrlGuard on the base URL, no redirects, short timeouts, the key registered with SecretScrubber;
 * nothing of the prompt, the answer or the key is logged. Errors become codes.
 */
final readonly class AiBrokerProvider implements AiProvider
{
    public const string KEY = 'ai_broker';

    private const int TIMEOUT_SECONDS = 15;

    /** The broker's terminal budget error (its own daily cap), recognised to report a distinct code. */
    private const string BROKER_BUDGET_MARKER = 'daily budget cap';

    public function __construct(
        private Http $http,
        private OutboundUrlGuard $guard,
        private AiSettingsReader $settings,
        private SecretScrubber $scrubber,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function submit(AiPrompt $prompt): AiJobRef
    {
        $settings = $this->settings->read();
        $body = [
            'messages' => $prompt->messages(),
            'max_tokens' => max(1, min(16384, $prompt->maxTokens)),
            'temperature' => max(0.0, min(2.0, $prompt->temperature)),
            'workflow' => 'sinhrm.'.$prompt->purpose->value,
        ];
        if ($settings->model !== null) {
            $body['model'] = $settings->model;
        }
        $format = $prompt->responseFormat();
        if ($format !== null) {
            $body['response_format'] = $format;
        }

        $capability = $prompt->capability ?? $settings->capabilityFor($prompt->purpose);
        $url = $settings->baseUrl.'/v1/jobs?capability='.rawurlencode($capability);
        $response = $this->send(fn (PendingRequest $r): Response => $r->post($url, $body), $url);
        if (! $response->successful()) {
            throw AiException::provider('http_'.$response->status());
        }
        $jobId = $response->json('job_id');
        if (! is_int($jobId) && ! (is_string($jobId) && ctype_digit($jobId))) {
            throw AiException::provider('bad_response');
        }

        return new AiJobRef(self::KEY, (string) $jobId, max(1, (int) $response->json('poll_after_s', 2)));
    }

    public function poll(AiJobRef $job): AiResult
    {
        if (! ctype_digit($job->jobId)) {
            return AiResult::error('ai_provider_bad_job');
        }
        $url = $this->settings->read()->baseUrl.'/v1/jobs/'.$job->jobId;
        try {
            $response = $this->send(fn (PendingRequest $r): Response => $r->get($url), $url);
        } catch (AiException $e) {
            return AiResult::error($e->errorCode);
        }
        if (! $response->successful()) {
            return AiResult::error('ai_provider_http_'.$response->status());
        }

        return match ($response->json('status')) {
            'pending', 'running' => AiResult::pending((int) $response->json('poll_after_s', 2)),
            'done' => AiResult::done(
                text: (string) $response->json('text', ''),
                model: is_string($response->json('model')) ? $response->json('model') : null,
                tokensIn: (int) $response->json('tokens_in', 0),
                tokensOut: (int) $response->json('tokens_out', 0),
                tokensCached: (int) $response->json('cache_read_tokens', 0),
                costUsd: (float) $response->json('cost_usd', 0),
                finishReason: is_string($response->json('finish_reason')) ? $response->json('finish_reason') : null,
            ),
            'error' => AiResult::error(str_contains(mb_strtolower((string) $response->json('error', '')), self::BROKER_BUDGET_MARKER)
                ? 'ai_provider_budget'
                : 'ai_provider_error'),
            default => AiResult::error('ai_provider_bad_response'),
        };
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call, string $url): Response
    {
        $settings = $this->settings->read();
        if ($settings->projectKey === null || $settings->projectKey === '') {
            throw AiException::notConfigured();
        }
        $blocked = $this->guard->check($url);
        if ($blocked !== null) {
            throw AiException::provider($blocked);
        }
        $this->scrubber->remember($settings->projectKey);
        try {
            return $call($this->http
                ->withOptions(['allow_redirects' => false])
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->withHeaders(['X-Project-Key' => $settings->projectKey]));
        } catch (Throwable) {
            // Never the exception text: it may contain the URL or headers.
            throw AiException::provider('connection_failed');
        }
    }
}
