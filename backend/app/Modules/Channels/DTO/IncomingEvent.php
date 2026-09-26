<?php

declare(strict_types=1);

namespace App\Modules\Channels\DTO;

use App\Modules\Channels\Enums\EventKind;
use App\Modules\Recruiting\Enums\Direction;
use Illuminate\Support\Carbon;

/**
 * One provider event after parsing. contact = the other party (phone / @username) for candidate matching;
 * thread = conversation id for replies and continuity; externalId = dedupe key within the channel.
 */
final readonly class IncomingEvent
{
    /** @param  array<string, mixed>  $meta  never secrets; duration_sec, recording_url, sender_name, status, … */
    public function __construct(
        public EventKind $kind,
        public Direction $direction,
        public Carbon $occurredAt,
        public ?string $contact = null,
        public ?string $body = null,
        public ?string $externalId = null,
        public ?string $thread = null,
        public array $meta = [],
    ) {}

    /** @param  array<string, mixed>  $meta */
    public function withMeta(array $meta): self
    {
        return new self($this->kind, $this->direction, $this->occurredAt, $this->contact, $this->body, $this->externalId,
            $this->thread, $meta + $this->meta);
    }
}
