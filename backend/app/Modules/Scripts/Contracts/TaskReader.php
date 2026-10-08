<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Contracts;

use App\Models\User;
use App\Modules\Scripts\DTO\TaskFilter;
use App\Modules\Scripts\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Reading the user's "Мої задачі" from another module (the home page). Implemented by Services\TaskService. */
interface TaskReader
{
    /**
     * Tasks the user may see by the filter; "today" / "overdue" are the user's day (Core\Support\UserTime).
     *
     * @return Collection<int, Task>
     */
    public function list(User $actor, TaskFilter $filter, ?Carbon $now = null): Collection;
}
