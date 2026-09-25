<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Support;

use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use SensitiveParameter;
use Throwable;

/**
 * POST https://oauth2.googleapis.com/token (form-encoded). Returns the decoded JSON of a 200 answer; any other
 * outcome becomes a GoogleException code. The response body (tokens) is never logged or put into messages; only
 * Google's short "error" code is kept (e.g. invalid_grant).
 */
final readonly class TokenEndpoint
{
    private const int TIMEOUT_SECONDS = 15;

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(private Http $http, private GoogleOAuthConfig $config) {}

    /**
     * @param  array<string, string>  $form  grant fields without client credentials
     * @return array<string, mixed>
     *
     * @throws GoogleException
     */
    public function request(#[SensitiveParameter] array $form): array
    {
        if (! $this->config->isConfigured()) {
            throw GoogleException::oauthNotConfigured();
        }
        try {
            $response = $this->http->asForm()->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false])
                ->post(GoogleOAuthConfig::TOKEN_URL, $form + [
                    'client_id' => (string) $this->config->clientId,
                    'client_secret' => (string) $this->config->clientSecret,
                ]);
        } catch (Throwable) {
            throw GoogleException::unreachable();
        }

        if (! $response->successful()) {
            if (self::errorCode($response) === 'invalid_grant') {
                throw GoogleException::reconnectRequired();
            }
            throw $response->status() === 401 ? GoogleException::unauthorized() : GoogleException::httpError($response->status());
        }
        $json = $response->json();
        if (! is_array($json) || ! isset($json['access_token']) || ! is_string($json['access_token'])) {
            throw GoogleException::badResponse();
        }

        return $json;
    }

    /** Google's short error code ("invalid_grant", …) or null; never free text. */
    private static function errorCode(Response $response): ?string
    {
        $error = $response->json('error');

        return is_string($error) && preg_match('/^[a-z_]{1,64}$/', $error) === 1 ? $error : null;
    }
}
