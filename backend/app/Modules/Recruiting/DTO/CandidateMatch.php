<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;

/**
 * Result of CandidateService::createOrMatch (imports, job-board e-mails): the candidate (new or the existing one with
 * a shared contact) and the application on the vacancy, if one was given.
 */
final readonly class CandidateMatch
{
    public function __construct(
        public Candidate $candidate,
        public bool $created,
        public ?Application $application = null,
        public bool $applicationCreated = false,
    ) {}
}
