<?php

declare(strict_types=1);

namespace App\Modules\Desk\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Desk\Enums\CaseStatus;
use App\Modules\Desk\Models\DeskCase;
use App\Modules\Desk\Providers\DeskServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * "Звернення" (/desk): my cases waiting for my answer (status "waiting").
 * "Черга звернень" (/desk/queue, HR only): open cases — the queue's default "open" filter.
 */
final readonly class DeskNavBadges implements NavBadgeProvider
{
    public function __construct(private DeskService $desk) {}

    public function badges(User $user): array
    {
        $badges = ['desk_mine' => $this->desk->mine($user)->filter(static fn (DeskCase $c): bool => $c->status === CaseStatus::Waiting)->count()];
        if (Gate::forUser($user)->allows(DeskServiceProvider::MANAGE)) {
            $badges['desk_queue'] = $this->desk->queue(['open' => true])->count();
        }

        return $badges;
    }
}
