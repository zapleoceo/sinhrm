<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\DTO;

use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;

/** How to treat a message: kind, parser (job_board) and the rule that decided (null = not from a rule). */
final readonly class Classification
{
    public function __construct(
        public SenderKind $kind,
        public ?ParserKey $parser = null,
        public ?int $ruleId = null,
        public string $engine = 'rules',
    ) {}
}
