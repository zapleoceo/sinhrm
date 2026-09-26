<?php

declare(strict_types=1);

namespace App\Modules\Audit\Support;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Integrations\Models\IntegrationSecret;

/**
 * Integration secrets: only the fact "secret <name> of integration <id> was set / cleared" and by whom.
 * The value, plain or encrypted, never reaches the log.
 */
final readonly class SecretAuditObserver
{
    public function __construct(private AuditLogger $logger) {}

    public function saved(IntegrationSecret $secret): void
    {
        if ($secret->wasRecentlyCreated || $secret->wasChanged('value')) {
            $this->write($secret, AuditAction::SecretSet);
        }
    }

    public function deleted(IntegrationSecret $secret): void
    {
        $this->write($secret, AuditAction::SecretCleared);
    }

    private function write(IntegrationSecret $secret, AuditAction $action): void
    {
        $this->logger->record('integration', (int) $secret->integration_id, $action, null, ['secret' => (string) $secret->name]);
    }
}
