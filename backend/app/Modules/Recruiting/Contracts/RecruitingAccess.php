<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Models\User;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;

/**
 * Recruiting visibility and rights for other modules (Scripts, Reports, Overview). Implemented by
 * Recruiting\Services\RecruitingScope; inside Recruiting the class itself is used.
 */
interface RecruitingAccess
{
    /** Branches, managed vacancies and interview applications the user sees; an inactive user sees nothing. */
    public function for(User $user): Scope;

    /** Recruiting writers (not viewers). */
    public function canWrite(User $user): bool;

    /** Pipelines and the reject reasons dictionary. */
    public function canManage(User $user): bool;

    public function canSeeCandidate(User $user, Candidate $candidate): bool;

    public function canSeeInboxItem(User $user, Touchpoint $touchpoint): bool;
}
