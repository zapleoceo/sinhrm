<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\DTO;

/** Gmail answer to users.messages.send: message id and thread id. */
final readonly class SentMail
{
    public function __construct(
        public string $id,
        public ?string $threadId,
    ) {}
}
