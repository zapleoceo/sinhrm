<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\DTO\AiUsage;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Support\AiSettingsReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Daily AI caps (admin settings): attempts and cost of ai_requests since 00:00 UTC. Checked by AiService before every
 * submit, including the one retry after an invalid answer. Logs counters only.
 */
final readonly class AiBudget
{
    public function __construct(
        private AiSettingsReader $settings,
        private AiRequestRepository $requests,
    ) {}

    public function usageToday(): AiUsage
    {
        return $this->requests->usageSince(self::dayStart());
    }

    /** @throws AiException ai_budget_exceeded when the requests or the cost of today reached the cap */
    public function assertWithin(): void
    {
        $settings = $this->settings->read();
        $usage = $this->usageToday();
        if ($usage->requests >= $settings->maxRequestsPerDay || $usage->costUsd >= $settings->maxCostPerDay) {
            Log::warning('ai.budget_exceeded', ['requests' => $usage->requests, 'cost_usd' => $usage->costUsd]);

            throw AiException::budgetExceeded();
        }
    }

    private static function dayStart(): Carbon
    {
        return Carbon::now('UTC')->startOfDay();
    }
}
