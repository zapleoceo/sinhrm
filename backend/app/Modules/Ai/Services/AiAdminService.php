<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Prompts\TestPrompt;
use App\Modules\Ai\Repositories\AiPromptVersionRepository;
use App\Modules\Ai\Support\AiSettingsReader;
use Illuminate\Support\Carbon;

/** Superadmin view of AI: availability per purpose, today's usage against the caps, and the "Test prompt" run. */
final readonly class AiAdminService
{
    public function __construct(
        private AiService $ai,
        private AiSettingsReader $settings,
        private AiProvider $provider,
        private AiRequestRepository $requests,
        private AiPromptVersionRepository $versions,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $settings = $this->settings->read();
        $usage = $this->ai->usageToday();
        $purposes = [];
        $prompts = [];
        foreach (AiPurpose::editable() as $purpose) {
            $purposes[$purpose->value] = $this->ai->unavailableReason($purpose);
            $prompts[$purpose->value] = $this->versions->active($purpose)?->version;
        }

        return [
            'provider' => $this->provider->key(),
            'capability' => $settings->capability,
            'capabilities' => $settings->capabilities,
            'model' => $settings->model,
            'configured' => $this->ai->unavailableReason(AiPurpose::Test) !== 'ai_not_configured',
            'available' => $this->ai->unavailableReason(AiPurpose::Test) === null,
            'purposes' => $purposes,
            // Active edited prompt version per purpose (null = built-in).
            'prompt_versions' => $prompts,
            'auto_screening' => $settings->autoScreening,
            'usage' => [
                'requests' => $usage->requests,
                'cost_usd' => $usage->costUsd,
                'tokens_in' => $usage->tokensIn,
                'tokens_out' => $usage->tokensOut,
                'tokens_cached' => $usage->tokensCached,
            ],
            'limits' => ['requests' => $settings->maxRequestsPerDay, 'cost_usd' => $settings->maxCostPerDay],
        ];
    }

    /** Stats periods of the admin panel: today (24 hourly buckets), 7 and 30 days (daily buckets). */
    public const array PERIODS = ['today', '7d', '30d'];

    /**
     * Detailed per-purpose statistics for a period (UTC, like the daily caps). Trials of the prompt editor are counted
     * under "prompt_trial", the test prompt under "test".
     *
     * @return array<string, mixed>
     */
    public function stats(string $period): array
    {
        $today = Carbon::now('UTC')->startOfDay();
        [$since, $buckets, $bucketSeconds] = match ($period) {
            '7d' => [$today->copy()->subDays(6), 7, 86400],
            '30d' => [$today->copy()->subDays(29), 30, 86400],
            default => [$today, 24, 3600],
        };
        $rows = $this->requests->statsSince($since, $buckets, $bucketSeconds);
        $purposes = [];
        foreach ([...AiPurpose::editable(), AiPurpose::Test, AiPurpose::PromptTrial] as $purpose) {
            $row = $rows[$purpose->value] ?? null;
            $purposes[$purpose->value] = $row === null ? null : $row + [
                'success_pct' => ($row['done'] + $row['failed']) === 0 ? null : (int) round(100 * $row['done'] / ($row['done'] + $row['failed'])),
            ];
        }

        return [
            'period' => in_array($period, self::PERIODS, true) ? $period : 'today',
            'since' => $since->toIso8601String(),
            'bucket' => $bucketSeconds === 3600 ? 'hour' : 'day',
            'purposes' => $purposes,
        ];
    }

    /**
     * Runs the tiny test prompt and waits for the answer (≤ 40 s).
     *
     * @return array<string, mixed>
     *
     * @throws AiException when AI is off, not configured or over the cap
     */
    public function test(): array
    {
        $outcome = $this->ai->run(TestPrompt::build());
        $request = $this->requests->find($outcome->requestId);

        return [
            'status' => $outcome->status,
            'error' => $outcome->error,
            'reply' => is_string($outcome->data['reply'] ?? null) ? $outcome->data['reply'] : null,
            'request_id' => $outcome->requestId,
            'model' => $request?->model,
            'tokens_in' => $request->tokens_in ?? 0,
            'tokens_out' => $request->tokens_out ?? 0,
            'tokens_cached' => $request->tokens_cached ?? 0,
            'cost_usd' => $request->cost_usd ?? 0.0,
        ];
    }
}
