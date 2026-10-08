<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Models\User;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\DTO\CandidateMatch;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Support\Carbon;

/**
 * Candidates arriving from machine sources of other modules (Google Sheets import, job-board e-mails).
 * Implemented by Recruiting\Services\CandidateService.
 */
interface CandidateIntake
{
    /**
     * No 409: a candidate sharing a normalized contact is reused, otherwise a new one is created; with a vacancy the
     * candidate is applied to it (first stage, at $at) unless already applied. The actor may be null (background job).
     *
     * @throws RecruitingException no_contacts | full_name_required
     */
    public function createOrMatch(?User $actor, CandidateData $data, ?Vacancy $vacancy = null, ?Carbon $at = null): CandidateMatch;
}
