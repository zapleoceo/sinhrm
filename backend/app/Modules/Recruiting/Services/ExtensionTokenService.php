<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Auth\DTO\PersonalTokenStatus;
use App\Modules\Auth\DTO\TokenKind;
use App\Modules\Auth\Services\PersonalTokens;

/**
 * Personal access token of the browser extension: ability "clipper" only, 90 days, one active per user (issuing a
 * new one revokes the previous). Sanctum accepts it on /api/clipper/* only (TokenScopes, RecruitingServiceProvider::boot).
 */
final readonly class ExtensionTokenService
{
    public const string NAME = 'extension';

    public const string ABILITY = 'clipper';

    public const int TTL_DAYS = 90;

    public function __construct(private PersonalTokens $tokens) {}

    public static function kind(): TokenKind
    {
        return new TokenKind(self::NAME, self::ABILITY, self::TTL_DAYS, 'recruiting.extension_token');
    }

    public function issue(User $user): PersonalTokenStatus
    {
        return $this->tokens->issue($user, self::kind());
    }

    public function status(User $user): PersonalTokenStatus
    {
        return $this->tokens->status($user, self::kind());
    }

    public function revoke(User $user): void
    {
        $this->tokens->revoke($user, self::kind());
    }
}
