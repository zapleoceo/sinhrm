<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\AiConversationHandler;
use App\Modules\Ai\Contracts\AiGateway;
use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\Contracts\AiTranscriber;
use App\Modules\Ai\DTO\AiAudio;
use App\Modules\Ai\DTO\AiJobRef;
use App\Modules\Ai\DTO\AiOutcome;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\DTO\AiUsage;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Enums\AiRequestStatus;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Support\AiHandlerRegistry;
use App\Modules\Ai\Support\AiSettingsReader;
use App\Modules\Ai\Support\JsonOutput;
use App\Modules\Ai\Support\PromptOverrides;
use App\Modules\Ai\Support\ToolEmulation;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * The only entry point to AI for other modules:
 * 0. an edited prompt version active in the admin replaces the instruction part (PromptOverrides);
 * 1. gate: global switch (AiPolicy) → provider configured (key + integration not "off") → purpose switched on;
 * 2. daily caps from ai_requests (attempts and cost since 00:00 UTC) → ai_budget_exceeded;
 * 3. submit, then poll with backoff within $waitSeconds (≤ 40 s: serverless requests end at 60 s);
 *    still pending → the request stays "pending" with its job id and the ai.poll job finishes it later;
 * 4. answer → strict JSON → purpose handler parse(); invalid → ONE retry, then ai_invalid_output
 *    (a conversational purpose — AiConversationHandler — gets the raw text + native tool calls instead of JSON);
 * 5. valid → pending→done (guarded) → handler apply() exactly once.
 * Logs carry ids, counters and codes only — never prompts, answers or keys.
 */
final readonly class AiService implements AiGateway
{
    /** Longest synchronous wait for an answer (serverless limit 60 s minus the rest of the request). */
    public const int WAIT_SECONDS = 40;

    /** First answer + one retry after invalid JSON. */
    public const int MAX_ATTEMPTS = 2;

    /** Seconds between polls; the broker's poll_after_s is used when it is longer. */
    private const array BACKOFF = [2, 2, 3, 5, 8, 13];

    /** ai_requests.capability / prompt_version of a speech-to-text request (polled through the transcriber). */
    public const string TRANSCRIPTION = 'transcription';

    public function __construct(
        private AiGate $gate,
        private AiProvider $provider,
        private AiTranscriber $transcriber,
        private AiSettingsReader $settings,
        private AiRequestRepository $requests,
        private AiHandlerRegistry $handlers,
        private PromptOverrides $overrides,
        private AiBudget $budget,
    ) {}

    /** null = AI can run for this purpose; otherwise the refusal code (ai_disabled | ai_not_configured | ai_purpose_disabled). */
    public function unavailableReason(AiPurpose $purpose): ?string
    {
        return $this->gate->unavailableReason($purpose);
    }

    public function available(AiPurpose $purpose): bool
    {
        return $this->unavailableReason($purpose) === null;
    }

    public function usageToday(): AiUsage
    {
        return $this->budget->usageToday();
    }

    /**
     * @param  array<string, int|string|bool>  $meta  ids/flags the handler needs later (never personal data)
     *
     * @throws AiException when AI is unavailable for the purpose or the daily cap is reached (nothing is sent then)
     */
    public function run(AiPrompt $prompt, ?string $subjectType = null, ?int $subjectId = null, array $meta = [], int $waitSeconds = self::WAIT_SECONDS): AiOutcome
    {
        $this->assertAvailable($prompt->purpose);
        $this->budget->assertWithin();
        // Active edited version from the admin prompt editor (instruction part only; OUTPUT stays code-owned).
        $prompt = $this->overrides->apply($prompt);
        $prompt = $prompt->withCapability($prompt->capability ?? $this->settings->read()->capabilityFor($prompt->purpose));
        // Tools without native tool support in the broker: emulate them through strict JSON (ToolEmulation).
        if ($prompt->tools !== null && ! $this->settings->read()->nativeTools) {
            $prompt = ToolEmulation::wrap($prompt);
            $meta[ToolEmulation::META_FLAG] = true;
        }

        $request = $this->requests->create([
            'purpose' => $prompt->purpose->value,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta === [] ? null : $meta,
            'provider' => $this->provider->key(),
            'capability' => $prompt->capability,
            'prompt_version' => $prompt->version,
            'status' => AiRequestStatus::Pending->value,
            'attempts' => 1,
        ]);

        return $this->submitAndAwait($request, fn (): AiJobRef => $this->provider->submit($prompt), $prompt, $waitSeconds);
    }

    /**
     * Speech → text for a purpose whose handler is an AiConversationHandler (it gets {text, tool_calls: []}): same
     * gate, daily caps, ai_requests row, waiting/deferral and ai.poll completion as run(); no retry (audio is not kept).
     *
     * @throws AiException when AI is unavailable for the purpose or the daily cap is reached (nothing is sent then)
     */
    public function transcribe(AiPurpose $purpose, AiAudio $audio, ?string $subjectType = null, ?int $subjectId = null, int $waitSeconds = self::WAIT_SECONDS): AiOutcome
    {
        $this->assertAvailable($purpose);
        $this->budget->assertWithin();
        $request = $this->requests->create([
            'purpose' => $purpose->value,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'provider' => $this->transcriber->key(),
            'capability' => self::TRANSCRIPTION,
            'prompt_version' => self::TRANSCRIPTION,
            'status' => AiRequestStatus::Pending->value,
            'attempts' => 1,
        ]);

        return $this->submitAndAwait($request, fn (): AiJobRef => $this->transcriber->submitAudio($audio, 'sinhrm.'.$purpose->value), null, $waitSeconds);
    }

    /**
     * One poll of a pending request (ai.poll job, "refresh" in the UI): done → handler applied, failed, or still
     * deferred. Finished requests are returned as they are (no provider call).
     */
    public function refresh(AiRequest $request): AiOutcome
    {
        if ($request->status === AiRequestStatus::Done) {
            return AiOutcome::done($request->id, []);
        }
        if ($request->status === AiRequestStatus::Failed || $request->job_id === null) {
            return AiOutcome::failed($request->id, $request->error ?? 'ai_provider_error');
        }
        $step = $this->handle($request, $this->poll($request, new AiJobRef($request->provider, $request->job_id)), null);

        return $step instanceof AiOutcome ? $step : AiOutcome::deferred($request->id);
    }

    /** Gives up on a request that stayed pending too long (ai.poll job). */
    public function expire(AiRequest $request): void
    {
        $this->fail($request, 'ai_timeout');
    }

    /**
     * First submit of a fresh request: provider refusal → failed; otherwise the job id is stored (attempt 1) and the
     * answer is awaited for at most WAIT_SECONDS.
     *
     * @param  Closure(): AiJobRef  $submit
     */
    private function submitAndAwait(AiRequest $request, Closure $submit, ?AiPrompt $prompt, int $waitSeconds): AiOutcome
    {
        try {
            $job = $submit();
        } catch (AiException $e) {
            return $this->fail($request, $e->errorCode);
        }
        $this->requests->setJob($request, mb_substr($job->jobId, 0, 64), 1);

        return $this->await($request, $job, $prompt, min(self::WAIT_SECONDS, max(0, $waitSeconds)));
    }

    /** $prompt null = nothing to resend (a transcription): an invalid answer then fails without a retry. */
    private function await(AiRequest $request, AiJobRef $job, ?AiPrompt $prompt, int $waitSeconds): AiOutcome
    {
        $deadline = Carbon::now()->addSeconds($waitSeconds);
        $delay = $job->pollAfterSeconds;
        $round = 0;
        while (true) {
            if ($job->immediate === null) {
                $remaining = (int) Carbon::now()->diffInSeconds($deadline, false);
                if ($remaining <= 0) {
                    return AiOutcome::deferred($request->id);
                }
                Sleep::for(min($remaining, max($delay, self::BACKOFF[min($round, count(self::BACKOFF) - 1)])))->seconds();
                $round++;
            }
            $result = $this->poll($request, $job);
            $step = $this->handle($request, $result, $prompt);
            if ($step instanceof AiOutcome) {
                return $step;
            }
            if ($step instanceof AiJobRef) {
                $job = $step;
                $delay = $job->pollAfterSeconds;
            } else {
                $delay = $result->pollAfterSeconds;
                // A synchronous provider never becomes "done" later.
                $job = new AiJobRef($job->provider, $job->jobId, $delay);
            }
        }
    }

    /** Transcriptions are polled through the transcriber, everything else through the chat provider. */
    private function poll(AiRequest $request, AiJobRef $job): AiResult
    {
        return $request->capability === self::TRANSCRIPTION ? $this->transcriber->poll($job) : $this->provider->poll($job);
    }

    /** @return AiOutcome|AiJobRef|null outcome = finished; job = retry submitted; null = still pending */
    private function handle(AiRequest $request, AiResult $result, ?AiPrompt $prompt): AiOutcome|AiJobRef|null
    {
        if ($result->isPending()) {
            return null;
        }
        if (! $result->isDone()) {
            return $this->fail($request, $result->error ?? 'ai_provider_error');
        }
        $this->requests->addUsage($request, $result);
        $handler = $this->handlers->get($request->purpose);
        try {
            if (($request->meta[ToolEmulation::META_FLAG] ?? false) === true) {
                $result = ToolEmulation::unwrap($result);
            }
            $json = $handler instanceof AiConversationHandler
                ? ['text' => (string) $result->text, 'tool_calls' => $result->toolCalls]
                : JsonOutput::decode($result->text) ?? throw InvalidAiOutput::because('not_json');
            $data = $handler->parse($json, $request);
        } catch (InvalidAiOutput $e) {
            Log::warning('ai.invalid_output', [
                'request_id' => $request->id,
                'purpose' => $request->purpose->value,
                'attempt' => $request->attempts,
                'reason' => $e->getMessage(),
                'finish_reason' => $result->finishReason,
            ]);
            // Also on the row (codes only): diagnosable without the platform logs.
            $this->requests->noteInvalid($request, $e->getMessage(), $result->finishReason);

            return $this->retry($request, $prompt) ?? $this->fail($request, 'ai_invalid_output');
        }
        if ($this->requests->markDone($request)) {
            $handler->apply($request, $data);
            Log::info('ai.request_done', [
                'request_id' => $request->id,
                'purpose' => $request->purpose->value,
                'attempts' => $request->attempts,
                'tokens_in' => $request->tokens_in,
                'tokens_out' => $request->tokens_out,
                'tokens_cached' => $request->tokens_cached,
                'cost_usd' => $request->cost_usd,
            ]);
        }

        return AiOutcome::done($request->id, $data);
    }

    /** One more attempt with the same prompt (rebuilt by the handler for deferred requests). */
    private function retry(AiRequest $request, ?AiPrompt $prompt): AiOutcome|AiJobRef|null
    {
        if ($request->attempts >= self::MAX_ATTEMPTS) {
            return null;
        }
        if ($prompt === null) {
            $rebuilt = $this->handlers->get($request->purpose)->rebuild($request);
            $prompt = $rebuilt === null ? null : $this->overrides->apply($rebuilt);
        }
        if ($prompt !== null && $request->capability !== null) {
            $prompt = $prompt->withCapability($request->capability);
        }
        if ($prompt === null) {
            return null;
        }
        try {
            $this->budget->assertWithin();
            $job = $this->provider->submit($prompt);
        } catch (AiException $e) {
            return $this->fail($request, $e->errorCode);
        }
        $this->requests->setJob($request, mb_substr($job->jobId, 0, 64), $request->attempts + 1);

        return $job;
    }

    private function fail(AiRequest $request, string $code): AiOutcome
    {
        if ($this->requests->markFailed($request, $code)) {
            Log::warning('ai.request_failed', ['request_id' => $request->id, 'purpose' => $request->purpose->value, 'error' => $code]);
            $this->handlers->get($request->purpose)->failed($request, $code);
        }

        return AiOutcome::failed($request->id, $code);
    }

    /** @throws AiException ai_disabled | ai_not_configured | ai_purpose_disabled */
    public function assertAvailable(AiPurpose $purpose): void
    {
        $this->gate->assertAvailable($purpose);
    }
}
