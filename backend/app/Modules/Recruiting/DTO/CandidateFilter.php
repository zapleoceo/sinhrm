<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\CandidateSource;

final readonly class CandidateFilter
{
    public function __construct(
        public ?string $q = null,
        public ?int $vacancyId = null,
        public ?int $stageId = null,
        public ?ApplicationStatus $status = null,
        public ?CandidateSource $source = null,
        public ?int $ownerId = null,
        public ?int $channelId = null,
        public int $perPage = 50,
    ) {}
}
