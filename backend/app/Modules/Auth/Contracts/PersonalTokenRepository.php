<?php

declare(strict_types=1);

namespace App\Modules\Auth\Contracts;

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/** Named Sanctum personal access tokens (one token per name and user): the extension token, the MCP token. */
interface PersonalTokenRepository
{
    /** Atomically replaces this name after checking locked active status and captured credential version.
     * @param  list<string>  $abilities
     */
    public function create(User $user, string $name, array $abilities, Carbon $expiresAt): NewAccessToken;

    public function find(User $user, string $name): ?PersonalAccessToken;

    /** @return int number of deleted tokens */
    public function deleteAll(User $user, string $name): int;
}
