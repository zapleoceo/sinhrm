<?php

declare(strict_types=1);

namespace App\Modules\Channels\DTO;

/** Provider answer to a send: message id (dedupe key of the outbound touchpoint) and the conversation id. */
final readonly class SentMessage
{
    public function __construct(
        public ?string $externalId,
        public ?string $thread = null,
    ) {}
}
