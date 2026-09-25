<?php

declare(strict_types=1);

namespace App\Modules\Auth\DTO;

/** Identity returned by Google after a successful OAuth exchange. Email is normalized to lowercase. */
final readonly class GoogleProfile
{
    public string $email;

    public function __construct(
        public string $googleId,
        string $email,
        public string $name,
        public ?string $avatarUrl,
        public bool $emailVerified,
    ) {
        $this->email = mb_strtolower(trim($email));
    }
}
