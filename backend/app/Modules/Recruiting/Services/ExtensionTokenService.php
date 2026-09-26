<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ExtensionTokenRepository;
use App\Modules\Recruiting\DTO\ExtensionTokenStatus;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Personal access token of the browser extension: ability "clipper" only, 90 days, one active per user (issuing a
 * new one revokes the previous). Sanctum accepts it on /api/clipper/* only (see RecruitingServiceProvider::boot).
 */
final readonly class ExtensionTokenService
{
    public const string NAME = 'extension';

    public const string ABILITY = 'clipper';

    public const int TTL_DAYS = 90;

    public function __construct(
        private ExtensionTokenRepository $tokens,
        private LoggerInterface $log,
    ) {}

    public function issue(User $user): ExtensionTokenStatus
    {
        $revoked = $this->tokens->deleteAll($user, self::NAME);
        $new = $this->tokens->create($user, self::NAME, [self::ABILITY], Carbon::now()->addDays(self::TTL_DAYS));
        $this->log->info('recruiting.extension_token_issued', ['user' => $user->id, 'revoked' => $revoked]);
        $token = $new->accessToken;

        return new ExtensionTokenStatus(true, $token->created_at, null, $token->expires_at, $new->plainTextToken);
    }

    public function status(User $user): ExtensionTokenStatus
    {
        $token = $this->tokens->find($user, self::NAME);
        if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return new ExtensionTokenStatus(false, $token?->created_at, $token?->last_used_at, $token?->expires_at);
        }

        return new ExtensionTokenStatus(true, $token->created_at, $token->last_used_at, $token->expires_at);
    }

    public function revoke(User $user): void
    {
        $revoked = $this->tokens->deleteAll($user, self::NAME);
        $this->log->info('recruiting.extension_token_revoked', ['user' => $user->id, 'revoked' => $revoked]);
    }
}
