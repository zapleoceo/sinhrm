<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Modules\GoogleWorkspace\Contracts\GoogleTokenProvider;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Support\TokenEndpoint;
use App\Modules\Integrations\Support\SecretScrubber;
use Illuminate\Support\Carbon;

/**
 * Access tokens: the cached one (vault + access_expires_at) while it has ≥ 60 s left, otherwise a refresh with the
 * stored refresh token. invalid_grant (revoked, or the 7-day expiry of an app in Google "Testing" mode) →
 * status error / reconnect_required + dashboard warning, and GoogleException::reconnectRequired().
 */
final readonly class GoogleTokenService implements GoogleTokenProvider
{
    public const int LEEWAY_SECONDS = 60;

    public function __construct(
        private GoogleConnectionStore $store,
        private TokenEndpoint $endpoint,
        private SecretScrubber $scrubber,
    ) {}

    /** @phpstan-impure */
    public function accessToken(GoogleService $service): string
    {
        $state = $this->store->state($service);
        if ($state->error === GoogleConnectionStore::RECONNECT_REQUIRED) {
            throw GoogleException::reconnectRequired();
        }
        $refresh = $this->store->refreshToken($service);
        if (! $state->usable || $refresh === null) {
            throw GoogleException::notConnected($service->value);
        }
        $now = Carbon::now();
        $cached = $this->store->cachedAccessToken($service, $now, self::LEEWAY_SECONDS);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $json = $this->endpoint->request(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        } catch (GoogleException $e) {
            if ($e->errorCode === GoogleConnectionStore::RECONNECT_REQUIRED) {
                $this->store->markReconnectRequired($service);
            }
            throw $e;
        }
        $token = (string) $json['access_token'];
        $this->scrubber->remember($token);
        $expiresIn = is_numeric($json['expires_in'] ?? null) ? (int) $json['expires_in'] : 3600;
        $this->store->storeAccessToken($service, $token, $now->copy()->addSeconds($expiresIn));

        return $token;
    }

    public function invalidate(GoogleService $service): void
    {
        $this->store->expireAccessToken($service);
    }
}
