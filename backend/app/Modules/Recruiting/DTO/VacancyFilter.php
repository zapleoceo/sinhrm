<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use App\Modules\Recruiting\Enums\VacancyStatus;

final readonly class VacancyFilter
{
    public function __construct(
        public ?string $q = null,
        public ?VacancyStatus $status = null,
        public ?int $branchId = null,
        public ?int $recruiterId = null,
        public int $perPage = 50,
    ) {}
}
