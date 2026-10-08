<?php

declare(strict_types=1);

namespace App\Modules\Auth\Contracts;

use App\Models\User;
use App\Modules\Auth\DTO\GoogleProfile;
use App\Modules\Auth\Enums\AppLocale;

interface UserRepository
{
    public function find(int $id): ?User;

    /**
     * Display names of the given users (missing ids are simply absent).
     *
     * @param  list<int>  $ids
     * @return array<int, string> id => name
     */
    public function namesByIds(array $ids): array;

    public function findByGoogleId(string $googleId): ?User;

    /** Case-insensitive lookup. */
    public function findByEmail(string $email): ?User;

    /** Creates the bootstrap superadmin (active, role superadmin) from a Google profile. */
    public function createSuperadmin(GoogleProfile $profile): User;

    /** Stores google_id, avatar and last_login_at. */
    public function recordGoogleLogin(User $user, GoogleProfile $profile): User;

    /** @param callable(User): void $grant fresh active user locked with the captured credential version */
    public function grantSession(User $user, callable $grant): void;

    public function updateLocale(User $user, AppLocale $locale): User;

    public function updateApprovalEmails(User $user, bool $on): User;
}
