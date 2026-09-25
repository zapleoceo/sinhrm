<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Support\GoogleOAuthConfig;
use App\Modules\GoogleWorkspace\Support\TokenEndpoint;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * OAuth consent for Gmail / Calendar / Sheets (separate from login, same OAuth client):
 * 1) authorizationUrl() — offline access + prompt=consent (so Google always returns a refresh token);
 * 2) complete() — exchanges the code, keeps only the services whose scopes were actually granted
 *    (the consent screen lets the user untick scopes) and stores the grant per service.
 */
final readonly class GoogleConnectService
{
    public function __construct(
        private GoogleOAuthConfig $config,
        private TokenEndpoint $endpoint,
        private GoogleConnectionStore $store,
    ) {}

    /**
     * @param  list<GoogleService>  $services
     *
     * @throws GoogleException
     */
    public function authorizationUrl(array $services, string $state): string
    {
        if (! $this->config->isConfigured()) {
            throw GoogleException::oauthNotConfigured();
        }
        $scopes = GoogleService::IDENTITY_SCOPES;
        foreach ($services as $service) {
            $scopes = [...$scopes, ...$service->scopes()];
        }

        return GoogleOAuthConfig::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', array_values(array_unique($scopes))),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  list<GoogleService>  $requested
     * @return array{connected: list<GoogleService>, missing: list<GoogleService>}
     *
     * @throws GoogleException
     */
    public function complete(#[SensitiveParameter] string $code, array $requested, User $actor): array
    {
        $json = $this->endpoint->request([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->redirectUri,
        ]);
        $refresh = isset($json['refresh_token']) && is_string($json['refresh_token']) ? $json['refresh_token'] : null;
        if ($refresh === null) {
            throw GoogleException::badResponse();
        }
        $granted = is_string($json['scope'] ?? null) ? array_values(array_filter(explode(' ', $json['scope']))) : [];
        $expiresIn = is_numeric($json['expires_in'] ?? null) ? (int) $json['expires_in'] : 3600;
        $email = self::emailFromIdToken(is_string($json['id_token'] ?? null) ? $json['id_token'] : null);

        $connected = [];
        $missing = [];
        foreach ($requested as $service) {
            if (array_diff($service->scopes(), $granted) !== []) {
                $missing[] = $service;

                continue;
            }
            $this->store->connect(
                $service,
                $refresh,
                (string) $json['access_token'],
                Carbon::now()->addSeconds($expiresIn),
                $email,
                $service->scopes(),
                $actor->id,
            );
            $connected[] = $service;
        }

        return ['connected' => $connected, 'missing' => $missing];
    }

    /**
     * The account e-mail from the id_token payload. The token came straight from Google's token endpoint over TLS,
     * so (per OpenID Connect) its signature need not be re-verified here; it is used only as a display label.
     */
    public static function emailFromIdToken(?string $idToken): ?string
    {
        $parts = $idToken === null ? [] : explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $email = is_array($payload) ? ($payload['email'] ?? null) : null;

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($email) : null;
    }
}
