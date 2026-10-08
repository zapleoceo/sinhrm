<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Core\Support\UserTime;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\Recruiting\Contracts\RecruitingAccess;
use App\Modules\Reports\DTO\ScopedContext;
use Illuminate\Support\Carbon;

/**
 * Builds the report scope from the existing access models (never a wider one).
 * $now is the current moment in the user's zone (Kyiv): "today" of headcount/tenure/age/pay is the user's date â€” at
 * 22:30 UTC on Oct 10 it is already Oct 11 in Kyiv. Never bind it to a query as is (format, not convert): dates only.
 */
final readonly class ScopedContextFactory
{
    public function __construct(private PeopleAccess $people, private RecruitingAccess $recruiting) {}

    public function for(User $user, ?Carbon $now = null): ScopedContext
    {
        return new ScopedContext($user, $this->people->for($user), $this->recruiting->for($user), UserTime::now($now));
    }
}
