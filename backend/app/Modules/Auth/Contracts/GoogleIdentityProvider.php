<?php

declare(strict_types=1);

namespace App\Modules\Auth\Contracts;

use App\Modules\Auth\DTO\GoogleProfile;
use App\Modules\Auth\Exceptions\GoogleAuthFailed;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Google OAuth behind an interface so tests never talk to Google. */
interface GoogleIdentityProvider
{
    /** Redirect to Google consent (scopes: openid email profile). */
    public function redirect(): RedirectResponse;

    /** @throws GoogleAuthFailed */
    public function profile(): GoogleProfile;
}
