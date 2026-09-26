<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Contracts;

use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\MailAgent\DTO\Classification;

/**
 * Decides the kind of a message. Binding: RulesMailClassifier (sender_rules, no AI). AI never decides: for queued
 * unknown senders AiMailClassifier only stores a suggestion that the superadmin confirms.
 */
interface MailClassifier
{
    /** null = this classifier cannot tell. */
    public function classify(GmailMessage $message): ?Classification;
}
