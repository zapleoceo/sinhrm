<?php

declare(strict_types=1);

namespace App\Modules\Overview\Contracts;

use App\Models\User;

/**
 * Warnings for the home page from other modules (e.g. "Google needs to be reconnected"). Modules register theirs
 * with $this->app->tag([...], DashboardNotices::class).
 */
interface DashboardNotices
{
    /** @return list<array{code: string, level: 'warning'|'error', params?: array<string, string>, link?: string}> */
    public function for(User $user): array;
}
