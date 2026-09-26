<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Contracts;

use App\Models\User;

/** Users a workflow step can be assigned to (reads only). */
interface AssigneeDirectory
{
    public function activeUser(int $id): ?User;

    /** The HR fallback: the first (lowest id) active superadmin or admin. */
    public function firstActiveAdminId(): ?int;
}
