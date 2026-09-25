<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\Auth\DTO\GoogleProfile;
use App\Modules\Auth\Enums\AppLocale;
use App\Modules\Auth\Enums\LoginDenial;
use App\Modules\Auth\Exceptions\LoginDenied;

/**
 * Invite-only sign-in rules. The only account that may enter uninvited is the configured
 * superadmin email (config auth.superadmin_email): its first login creates the user with the superadmin role.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ?string $superadminEmail,
    ) {}

    /** @throws LoginDenied */
    public function handleGoogle(GoogleProfile $profile): User
    {
        if (! $profile->emailVerified) {
            throw new LoginDenied(LoginDenial::EmailUnverified);
        }

        $user = $this->users->findByGoogleId($profile->googleId) ?? $this->users->findByEmail($profile->email);

        if ($user === null) {
            if ($this->isSuperadminEmail($profile->email)) {
                return $this->users->createSuperadmin($profile);
            }

            throw new LoginDenied(LoginDenial::NotInvited);
        }

        if (! $user->isActive()) {
            throw new LoginDenied(LoginDenial::Blocked);
        }

        return $this->users->recordGoogleLogin($user, $profile);
    }

    public function changeLocale(User $user, AppLocale $locale): User
    {
        return $this->users->updateLocale($user, $locale);
    }

    private function isSuperadminEmail(string $email): bool
    {
        $configured = mb_strtolower(trim((string) $this->superadminEmail));

        return $configured !== '' && hash_equals($configured, $email);
    }
}
