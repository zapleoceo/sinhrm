<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Modules\GoogleWorkspace\DTO\ConnectionState;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\Integrations\Contracts\IntegrationRepository;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Enums\LogLevel;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * Persistence of Google connections on top of the Integrations module: tokens in the SecretVault (encrypted),
 * everything else in the integration row (status, non-secret settings). The only class of the module that writes
 * connection state.
 *
 * Settings keys (not editable in the Integrations UI — the Google definitions have no fields):
 * account_email, scopes (list), connected_by (user id), connected_at, access_expires_at (ISO).
 */
final readonly class GoogleConnectionStore
{
    public const string REFRESH_TOKEN = 'refresh_token';

    public const string ACCESS_TOKEN = 'access_token';

    public const string RECONNECT_REQUIRED = 'reconnect_required';

    public function __construct(
        private IntegrationRepository $integrations,
        private SecretVault $vault,
    ) {}

    /**
     * Stores a fresh grant for one service and marks it connected.
     *
     * @param  list<string>  $scopes
     */
    public function connect(
        GoogleService $service,
        #[SensitiveParameter] string $refreshToken,
        #[SensitiveParameter] string $accessToken,
        Carbon $accessExpiresAt,
        ?string $accountEmail,
        array $scopes,
        int $userId,
    ): void {
        $key = $service->integrationKey();
        $this->vault->put($key, self::REFRESH_TOKEN, $refreshToken, $userId);
        $this->vault->put($key, self::ACCESS_TOKEN, $accessToken, $userId);

        $integration = $this->integrations->findOrCreate($key);
        $integration->status = IntegrationStatus::Connected;
        $integration->last_error = null;
        $integration->last_checked_at = Carbon::now();
        $integration->settings = array_merge($integration->settings, [
            'account_email' => $accountEmail,
            'scopes' => $scopes,
            'connected_by' => $userId,
            'connected_at' => Carbon::now()->toIso8601String(),
            'access_expires_at' => $accessExpiresAt->toIso8601String(),
        ]);
        $this->integrations->save($integration);
        // Only the scope names: never tokens or codes.
        $this->integrations->log($integration, LogLevel::Info, 'google_connected', ['by' => $userId, 'scopes' => $scopes]);
    }

    public function state(GoogleService $service): ConnectionState
    {
        $integration = $this->integrations->find($service->integrationKey());
        $status = $integration->status ?? IntegrationStatus::Off;
        $settings = $integration->settings ?? [];
        $hasRefresh = array_key_exists(self::REFRESH_TOKEN, $this->vault->describe($service->integrationKey()));
        $scopes = is_array($settings['scopes'] ?? null) ? array_values(array_filter($settings['scopes'], 'is_string')) : [];
        $connectedAt = is_string($settings['connected_at'] ?? null) ? Carbon::parse($settings['connected_at']) : null;

        return new ConnectionState(
            service: $service,
            status: $status->value,
            usable: $status === IntegrationStatus::Connected && $hasRefresh,
            accountEmail: is_string($settings['account_email'] ?? null) ? $settings['account_email'] : null,
            scopes: $scopes,
            error: $integration?->last_error,
            connectedAt: $connectedAt,
        );
    }

    /** User who connected the service (the actor of background jobs), if any. */
    public function connectedBy(GoogleService $service): ?int
    {
        $by = $this->integrations->find($service->integrationKey())?->settings['connected_by'] ?? null;

        return is_int($by) ? $by : null;
    }

    public function refreshToken(GoogleService $service): ?string
    {
        return $this->vault->get($service->integrationKey(), self::REFRESH_TOKEN);
    }

    /** Cached access token when it is still valid for at least $leewaySeconds. */
    public function cachedAccessToken(GoogleService $service, Carbon $now, int $leewaySeconds): ?string
    {
        $expires = $this->integrations->find($service->integrationKey())?->settings['access_expires_at'] ?? null;
        if (! is_string($expires) || Carbon::parse($expires)->lte($now->copy()->addSeconds($leewaySeconds))) {
            return null;
        }

        return $this->vault->get($service->integrationKey(), self::ACCESS_TOKEN);
    }

    public function storeAccessToken(GoogleService $service, #[SensitiveParameter] string $accessToken, Carbon $expiresAt): void
    {
        $this->vault->put($service->integrationKey(), self::ACCESS_TOKEN, $accessToken);
        $integration = $this->integrations->findOrCreate($service->integrationKey());
        $integration->settings = array_merge($integration->settings, ['access_expires_at' => $expiresAt->toIso8601String()]);
        $this->integrations->save($integration);
    }

    /** Drops the cached access token (e.g. after a 401), so the next call refreshes it. */
    public function expireAccessToken(GoogleService $service): void
    {
        $integration = $this->integrations->find($service->integrationKey());
        if ($integration === null) {
            return;
        }
        $integration->settings = array_merge($integration->settings, ['access_expires_at' => null]);
        $this->integrations->save($integration);
    }

    /** invalid_grant: the refresh token is dead. Status error + code, a warning for the dashboard. */
    public function markReconnectRequired(GoogleService $service): void
    {
        $integration = $this->integrations->findOrCreate($service->integrationKey());
        $integration->status = IntegrationStatus::Error;
        $integration->last_error = self::RECONNECT_REQUIRED;
        $integration->last_checked_at = Carbon::now();
        $integration->settings = array_merge($integration->settings, ['access_expires_at' => null]);
        $this->integrations->save($integration);
        $this->vault->forget($service->integrationKey(), self::ACCESS_TOKEN);
        $this->integrations->log($integration, LogLevel::Warning, self::RECONNECT_REQUIRED);
    }

    /** @param  array<string, int|string|bool|null>  $context  counters/codes only */
    public function log(GoogleService $service, LogLevel $level, string $message, array $context = []): void
    {
        $this->integrations->log($this->integrations->findOrCreate($service->integrationKey()), $level, $message, $context);
    }
}
