<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/** Captured only at explicit login; ordinary requests must never refresh this stamp. */
final class CredentialSession
{
    public const string VERSION_KEY = 'auth.credential_version';
}
