<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Services;

use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\Integrations\Contracts\AiPolicy;
use App\Modules\MailAgent\Contracts\MailClassifier;
use App\Modules\MailAgent\DTO\Classification;
use Psr\Log\LoggerInterface;

/**
 * Placeholder for AI classification of unknown senders. It NEVER calls a provider: with AI off (default) it declines;
 * with AI on it reports "not configured" (models and prompts are not approved yet) and declines too, so the rules
 * decide. Wire a real provider here only after the owner's approval (CLAUDE.md rule 7).
 */
final readonly class AiMailClassifier implements MailClassifier
{
    public function __construct(private AiPolicy $policy, private LoggerInterface $log) {}

    public function classify(GmailMessage $message): ?Classification
    {
        if (! $this->policy->enabled()) {
            return null;
        }
        $this->log->info('mail.ai_classifier_not_configured');

        return null;
    }
}
