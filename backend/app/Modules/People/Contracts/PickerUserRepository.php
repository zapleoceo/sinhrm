<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;

/** Id → name resolution of active system users for the person picker (search itself reuses UserAdminRepository). */
interface PickerUserRepository
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    public function activeByIds(array $ids): Collection;
}
