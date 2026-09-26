<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/** Sanctum personal access tokens of the browser extension (one named token per user). */
interface ExtensionTokenRepository
{
    /** @param  list<string>  $abilities */
    public function create(User $user, string $name, array $abilities, Carbon $expiresAt): NewAccessToken;

    public function find(User $user, string $name): ?PersonalAccessToken;

    /** @return int number of deleted tokens */
    public function deleteAll(User $user, string $name): int;
}
