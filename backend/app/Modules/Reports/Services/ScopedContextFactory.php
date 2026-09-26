<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Reports\DTO\ScopedContext;
use Illuminate\Support\Carbon;

/** Builds the report scope from the existing access models (never a wider one). */
final readonly class ScopedContextFactory
{
    public function __construct(private PeopleScope $people, private RecruitingScope $recruiting) {}

    public function for(User $user, ?Carbon $now = null): ScopedContext
    {
        return new ScopedContext($user, $this->people->for($user), $this->recruiting->for($user), $now ?? Carbon::now());
    }
}
