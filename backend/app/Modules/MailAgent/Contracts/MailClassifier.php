<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Contracts;

use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\MailAgent\DTO\Classification;

/**
 * Decides the kind of a message. Default binding: RulesMailClassifier (sender_rules, no AI). AiMailClassifier is
 * consulted only when AiPolicy is enabled, and never calls a provider until the owner approves models and prompts.
 */
interface MailClassifier
{
    /** null = this classifier cannot tell. */
    public function classify(GmailMessage $message): ?Classification;
}
