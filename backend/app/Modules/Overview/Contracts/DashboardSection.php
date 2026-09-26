<?php

declare(strict_types=1);

namespace App\Modules\Overview\Contracts;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A block of the home page contributed by another module (e.g. TimeOff: who is out today, my approvals).
 * Its data lands under data.<key>() of GET /api/dashboard. Register with $this->app->tag([...], DashboardSection::class).
 */
interface DashboardSection
{
    public function key(): string;

    /** @return array<string, mixed> already scoped to the user */
    public function data(User $user, Carbon $now): array;
}
