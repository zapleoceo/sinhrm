<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use Illuminate\Support\Carbon;

/**
 * Contract for future integrations (telephony, messengers, mail): one message/call captured outside the product.
 * contact = the other party as the source gives it (phone, e-mail, @username); it is matched to a candidate.
 * externalId = id in the source system; a repeated delivery with the same (channel, externalId) is ignored.
 */
final readonly class IncomingMessage
{
    /** @param  array<string, mixed>  $meta  duration_sec, recording_url, … (never secrets) */
    public function __construct(
        public Channel $channel,
        public Direction $direction,
        public Carbon $occurredAt,
        public ?string $contact,
        public ?string $body = null,
        public ?string $externalId = null,
        public ?string $integrationKey = null,
        public ?int $branchId = null,
        public ?int $authorId = null,
        public bool $viaProduct = false,
        public array $meta = [],
    ) {}
}
