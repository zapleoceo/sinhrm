<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\DTO;

/**
 * One plain-text mail. The text is user input: it goes out as text/plain and as an HTML part built only from the
 * escaped text (no user HTML is ever sent). Replying: threadId = Gmail thread of the candidate's mail,
 * inReplyTo = its RFC 2822 Message-ID (In-Reply-To / References headers), so the reply lands in the same conversation.
 */
final readonly class OutgoingMail
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $text,
        public ?string $toName = null,
        public ?string $threadId = null,
        public ?string $inReplyTo = null,
    ) {}
}
