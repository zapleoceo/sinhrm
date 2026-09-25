<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

/** Reason codes passed to the SPA as /login?error=<code>. Never contain personal data. */
enum LoginDenial: string
{
    case NotInvited = 'not_invited';
    case Blocked = 'blocked';
    case EmailUnverified = 'email_unverified';
    case OauthFailed = 'oauth_failed';
}
