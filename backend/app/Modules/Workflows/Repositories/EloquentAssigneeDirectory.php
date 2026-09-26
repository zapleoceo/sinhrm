<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Repositories;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Workflows\Contracts\AssigneeDirectory;

final class EloquentAssigneeDirectory implements AssigneeDirectory
{
    public function activeUser(int $id): ?User
    {
        $user = User::query()->find($id);

        return $user !== null && $user->isActive() ? $user : null;
    }

    public function firstActiveAdminId(): ?int
    {
        $id = User::query()
            ->where('status', UserStatus::Active->value)
            ->role([UserRole::Superadmin->value, UserRole::Admin->value])
            ->orderBy('id')
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
