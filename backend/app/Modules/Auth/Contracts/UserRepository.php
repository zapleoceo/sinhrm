<?php

declare(strict_types=1);

namespace App\Modules\Auth\Contracts;

use App\Models\User;
use App\Modules\Auth\DTO\GoogleProfile;
use App\Modules\Auth\Enums\AppLocale;

interface UserRepository
{
    public function find(int $id): ?User;

    public function findByGoogleId(string $googleId): ?User;

    /** Case-insensitive lookup. */
    public function findByEmail(string $email): ?User;

    /** Creates the bootstrap superadmin (active, role superadmin) from a Google profile. */
    public function createSuperadmin(GoogleProfile $profile): User;

    /** Stores google_id, avatar and last_login_at. */
    public function recordGoogleLogin(User $user, GoogleProfile $profile): User;

    public function updateLocale(User $user, AppLocale $locale): User;
}
