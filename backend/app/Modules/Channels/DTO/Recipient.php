<?php

declare(strict_types=1);

namespace App\Modules\Channels\DTO;

use Illuminate\Support\Carbon;

/**
 * Where to send a message to a candidate: contacts from the card and the conversation already known from earlier
 * touchpoints of the channel (thread = chat / user id; lastInboundAt = when the candidate last wrote).
 */
final readonly class Recipient
{
    public function __construct(
        public ?string $phone,
        public ?string $telegramUsername,
        public ?string $thread,
        public ?Carbon $lastInboundAt,
    ) {}
}
