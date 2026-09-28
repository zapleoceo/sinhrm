<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Models\User;
use App\Modules\Auth\Contracts\PersonalTokenRepository;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

final class SanctumPersonalTokenRepository implements PersonalTokenRepository
{
    public function create(User $user, string $name, array $abilities, Carbon $expiresAt): NewAccessToken
    {
        return $user->createToken($name, $abilities, $expiresAt);
    }

    public function find(User $user, string $name): ?PersonalAccessToken
    {
        return PersonalAccessToken::query()->whereMorphedTo('tokenable', $user)->where('name', $name)->latest('id')->first();
    }

    public function deleteAll(User $user, string $name): int
    {
        return (int) PersonalAccessToken::query()->whereMorphedTo('tokenable', $user)->where('name', $name)->delete();
    }
}
