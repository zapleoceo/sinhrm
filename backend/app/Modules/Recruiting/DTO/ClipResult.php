<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use App\Modules\Recruiting\Models\Candidate;

/** Outcome of an extension import: a new candidate (201) or an existing one matched by profile URL / contacts (200). */
final readonly class ClipResult
{
    public function __construct(
        public Candidate $candidate,
        public bool $created,
    ) {}
}
