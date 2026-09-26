<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Scripts\DTO\TaskFilter;

/** "Мої задачі" (/tasks): my open tasks — the page's default filter (mine, not done). */
final readonly class TaskNavBadges implements NavBadgeProvider
{
    public function __construct(private TaskService $tasks) {}

    public function badges(User $user): array
    {
        return ['tasks' => $this->tasks->count($user, new TaskFilter(mine: true))];
    }
}
