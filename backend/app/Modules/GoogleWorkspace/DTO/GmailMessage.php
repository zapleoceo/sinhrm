<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\DTO;

use Illuminate\Support\Carbon;

/**
 * One Gmail message reduced to what the mail agent needs: sender, subject, plain text, time, labels, and for replies
 * the Gmail thread id and the RFC 2822 Message-ID header.
 */
final readonly class GmailMessage
{
    /** @param  list<string>  $labelIds */
    public function __construct(
        public string $id,
        public Carbon $receivedAt,
        public ?string $fromEmail,
        public ?string $fromName,
        public string $subject,
        public string $text,
        public array $labelIds = [],
        public ?string $threadId = null,
        public ?string $messageId = null,
    ) {}

    public function isSent(): bool
    {
        return in_array('SENT', $this->labelIds, true);
    }
}
