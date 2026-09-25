<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Support;

use App\Modules\GoogleWorkspace\Contracts\GoogleTokenProvider;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Authorized JSON calls to fixed Google REST hosts (gmail/www/sheets.googleapis.com) — no google/apiclient,
 * it is too heavy for the serverless bundle. Hosts are constants, never user input, so no SSRF guard is needed;
 * redirects are still disabled. A 401 refreshes the token once and retries. Errors become GoogleException codes;
 * response bodies and exception texts are never logged.
 */
final readonly class GoogleApi
{
    private const int TIMEOUT_SECONDS = 15;

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(private Http $http, private GoogleTokenProvider $tokens) {}

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     *
     * @throws GoogleException
     */
    public function get(GoogleService $service, string $url, array $query = []): array
    {
        return $this->send($service, 'GET', $url, $query, null);
    }

    /**
     * @param  array<string, string|int>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws GoogleException
     */
    public function post(GoogleService $service, string $url, array $query, array $body): array
    {
        return $this->send($service, 'POST', $url, $query, $body);
    }

    /**
     * @param  array<string, string|int>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(GoogleService $service, string $method, string $url, array $query, ?array $body, bool $retried = false): array
    {
        $token = $this->tokens->accessToken($service);
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        try {
            $request = $this->http->withToken($token)->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false]);
            $response = $method === 'GET' ? $request->get($url) : $request->post($url, $body ?? []);
        } catch (Throwable) {
            throw GoogleException::unreachable();
        }

        if ($response->status() === 401 && ! $retried) {
            $this->tokens->invalidate($service);

            return $this->send($service, $method, $url, [], $body, true);
        }

        return self::decode($response);
    }

    /** @return array<string, mixed> */
    private static function decode(Response $response): array
    {
        if (! $response->successful()) {
            throw match ($response->status()) {
                401 => GoogleException::unauthorized(),
                403 => GoogleException::forbidden(),
                404 => GoogleException::notFound(),
                default => GoogleException::httpError($response->status()),
            };
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw GoogleException::badResponse();
        }

        /** @var array<string, mixed> $json */
        return $json;
    }
}
