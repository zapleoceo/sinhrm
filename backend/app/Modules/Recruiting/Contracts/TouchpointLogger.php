<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Models\User;
use App\Modules\Recruiting\DTO\TouchpointData;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;

/** A touch logged by a user from another module (a meeting from GoogleWorkspace). Implemented by Services\TouchpointService. */
interface TouchpointLogger
{
    /**
     * Logs a manual touch; application_id, when given, must belong to the candidate, otherwise the latest active
     * application is used.
     *
     * @throws RecruitingException
     */
    public function log(User $actor, Candidate $candidate, TouchpointData $data): Touchpoint;
}
