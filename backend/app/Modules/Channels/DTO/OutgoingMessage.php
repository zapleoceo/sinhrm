<?php

declare(strict_types=1);

namespace App\Modules\Channels\DTO;

use Illuminate\Support\Carbon;

/** A text message to a candidate, sent from the card. */
final readonly class OutgoingMessage
{
    public function __construct(
        public Recipient $recipient,
        public string $text,
        public Carbon $now,
    ) {}
}
