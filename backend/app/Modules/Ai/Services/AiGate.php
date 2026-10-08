<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Support\AiSettingsReader;
use App\Modules\Integrations\Contracts\AiPolicy;

/**
 * Whether AI may run for a purpose: global switch (AiPolicy) → provider configured (the broker needs its key +
 * integration not "off"; other providers their own key) → purpose switched on in the admin. AiService asks it before
 * anything is stored or sent.
 */
final readonly class AiGate
{
    public function __construct(
        private AiPolicy $policy,
        private AiProvider $provider,
        private AiSettingsReader $settings,
    ) {}

    /** null = AI can run for this purpose; otherwise the refusal code (ai_disabled | ai_not_configured | ai_purpose_disabled). */
    public function unavailableReason(AiPurpose $purpose): ?string
    {
        if (! $this->policy->enabled()) {
            return 'ai_disabled';
        }
        $settings = $this->settings->read();
        if (! $this->providerConfigured()) {
            return 'ai_not_configured';
        }

        return $settings->purposeEnabled($purpose) ? null : 'ai_purpose_disabled';
    }

    /** @throws AiException ai_disabled | ai_not_configured | ai_purpose_disabled */
    public function assertAvailable(AiPurpose $purpose): void
    {
        $reason = $this->unavailableReason($purpose);
        if ($reason !== null) {
            throw match ($reason) {
                'ai_disabled' => AiException::disabled(),
                'ai_not_configured' => AiException::notConfigured(),
                default => AiException::purposeDisabled(),
            };
        }
    }

    private function providerConfigured(): bool
    {
        return $this->provider->key() === AiBrokerProvider::KEY ? $this->settings->read()->configured() : true;
    }
}
