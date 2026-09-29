<?php

declare(strict_types=1);

namespace App\Modules\People\Repositories;

use App\Models\User;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\People\Contracts\PickerUserRepository;
use Illuminate\Support\Collection;

final class EloquentPickerUserRepository implements PickerUserRepository
{
    public function activeByIds(array $ids): Collection
    {
        return User::query()->whereIn('id', $ids)->where('status', UserStatus::Active->value)
            ->orderBy('name')->orderBy('id')->get();
    }
}
