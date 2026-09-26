<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Models\User;

/**
 * Counters next to sidebar items (GET /api/nav/badges). Each module reports its own items for the given user,
 * reusing the same service/scope as the list page, so the number equals what that page shows.
 * Modules register providers with $this->app->tag([...], NavBadgeProvider::class).
 */
interface NavBadgeProvider
{
    /**
     * Only keys the user may see (a missing key = no badge); values are counts, 0 allowed.
     *
     * @return array<string, int>
     */
    public function badges(User $user): array;
}
