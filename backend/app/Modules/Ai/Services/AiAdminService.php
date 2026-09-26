<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Prompts\TestPrompt;
use App\Modules\Ai\Support\AiSettingsReader;

/** Superadmin view of AI: availability per purpose, today's usage against the caps, and the "Test prompt" run. */
final readonly class AiAdminService
{
    public function __construct(
        private AiService $ai,
        private AiSettingsReader $settings,
        private AiProvider $provider,
        private AiRequestRepository $requests,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $settings = $this->settings->read();
        $usage = $this->ai->usageToday();
        $purposes = [];
        foreach ([AiPurpose::ScriptEvaluation, AiPurpose::MailClassification, AiPurpose::CandidateScreening] as $purpose) {
            $purposes[$purpose->value] = $this->ai->unavailableReason($purpose);
        }

        return [
            'provider' => $this->provider->key(),
            'capability' => $settings->capability,
            'capabilities' => $settings->capabilities,
            'model' => $settings->model,
            'configured' => $this->ai->unavailableReason(AiPurpose::Test) !== 'ai_not_configured',
            'available' => $this->ai->unavailableReason(AiPurpose::Test) === null,
            'purposes' => $purposes,
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
