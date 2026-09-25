<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

final readonly class MoveData
{
    public function __construct(
        public int $stageId,
        public ?string $reason = null,
        public ?int $rejectReasonId = null,
    ) {}
}
