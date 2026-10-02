<?php

declare(strict_types=1);

namespace App\Modules\Users\DTO;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Users\Enums\UserSort;
use Illuminate\Support\Carbon;

/**
 * Users list: q = name or e-mail contains (the «user» column filter); lastLoginFrom inclusive, lastLoginTo exclusive
 * (the request turns the «to» day into the next midnight). sort/descending order the page.
 */
final readonly class UserFilter
{
    public function __construct(
        public ?string $q = null,
        public ?UserStatus $status = null,
        public ?UserRole $role = null,
        public int $perPage = 20,
        public ?Carbon $lastLoginFrom = null,
        public ?Carbon $lastLoginTo = null,
        public UserSort $sort = UserSort::Name,
        public bool $descending = false,
    ) {}
}
