<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Models\User;
use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\Auth\DTO\GoogleProfile;
use App\Modules\Auth\Enums\AppLocale;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use Illuminate\Support\Facades\DB;

final class EloquentUserRepository implements UserRepository
{
    public function findByGoogleId(string $googleId): ?User
    {
        return User::query()->where('google_id', $googleId)->first();
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
    }

    public function createSuperadmin(GoogleProfile $profile): User
    {
        return DB::transaction(function () use ($profile): User {
            $user = new User;
            $user->forceFill([
                'name' => $profile->name,
                'email' => $profile->email,
                'google_id' => $profile->googleId,
                'avatar_url' => $profile->avatarUrl,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'last_login_at' => now(),
            ])->save();
            $user->assignRole(UserRole::Superadmin->value);

            return $user;
        });
    }

    public function recordGoogleLogin(User $user, GoogleProfile $profile): User
    {
        $user->forceFill([
            'google_id' => $profile->googleId,
            'avatar_url' => $profile->avatarUrl,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'last_login_at' => now(),
        ])->save();

        return $user;
    }

    public function updateLocale(User $user, AppLocale $locale): User
    {
        $user->forceFill(['locale' => $locale->value])->save();

        return $user;
    }
}
