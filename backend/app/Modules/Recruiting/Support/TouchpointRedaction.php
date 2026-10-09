<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Support;

use App\Models\User;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read the text of a sensitive touch. The candidate card (and with it the timeline, the inbox and the script
 * evaluation) is open to branch viewers and to interviewers assigned to an application; the offer is not — its text
 * carries the salary and only ApplicationPolicy::offer may see it. Touches sent as an offer are marked
 * meta.kind = Touchpoint::KIND_OFFER, and every read path runs them through here.
 */
final class TouchpointRedaction
{
    /** True when the touch carries restricted text that this user may not read. */
    public static function restricted(Touchpoint $touchpoint, ?User $user): bool
    {
        if (($touchpoint->meta['kind'] ?? null) !== Touchpoint::KIND_OFFER) {
            return false;
        }
        $application = $touchpoint->application_id === null ? null : $touchpoint->application;
        if ($user === null || $application === null) {
            return true; // nothing to authorise against → never expose the text
        }

        return Gate::forUser($user)->denies('offer', $application);
    }
}
