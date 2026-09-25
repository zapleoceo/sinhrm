<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\DTO;

use App\Modules\MailAgent\Enums\MailOutcome;

/** What happened to one message; becomes a mail_messages row. */
final readonly class ProcessResult
{
    public function __construct(
        public MailOutcome $outcome,
        public ?Classification $classification = null,
        public ?int $candidateId = null,
        public ?int $touchpointId = null,
        public ?string $error = null,
        public bool $taskCreated = false,
    ) {}
}
