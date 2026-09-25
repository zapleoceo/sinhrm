<?php

declare(strict_types=1);

namespace App\Modules\Users\DTO;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;

final readonly class UserFilter
{
    public function __construct(
        public ?string $q = null,
        public ?UserStatus $status = null,
        public ?UserRole $role = null,
        public int $perPage = 20,
    ) {}
}
