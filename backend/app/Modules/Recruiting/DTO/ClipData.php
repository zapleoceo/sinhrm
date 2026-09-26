<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use App\Modules\Recruiting\Enums\ClipperSite;

/** One profile page imported by the browser extension. $profileUrl is already normalized (ClipperSite::normalizeUrl). */
final readonly class ClipData
{
    public function __construct(
        public string $fullName,
        public ClipperSite $site,
        public string $profileUrl,
        public ?string $headline = null,
        public ?string $location = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $telegram = null,
        public ?string $summary = null,
        public ?int $vacancyId = null,
    ) {}
}
