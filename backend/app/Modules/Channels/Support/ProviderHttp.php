<?php

declare(strict_types=1);

namespace App\Modules\Channels\Support;

use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Integrations\Support\OutboundUrlGuard;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Outbound calls to providers: SSRF guard on every URL, no redirects, short timeout. Exception texts are never kept
 * (a Telegram URL contains the bot token): any transport failure becomes ChannelException::sendFailed().
 */
final readonly class ProviderHttp
{
    private const int TIMEOUT_SECONDS = 10;

    public function __construct(private Http $http, private OutboundUrlGuard $guard) {}

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     *
     * @throws ChannelException
     */
    public function postJson(string $url, array $body, array $headers = [], ?string $bearer = null): Response
    {
        return $this->send($url, $headers, $bearer, false, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     *
     * @throws ChannelException
     */
    public function postForm(string $url, array $body, array $headers = []): Response
    {
        return $this->send($url, $headers, null, true, $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    private function send(string $url, array $headers, ?string $bearer, bool $form, array $body): Response
    {
        if ($this->guard->check($url) !== null) {
            throw ChannelException::sendFailed();
        }
        try {
            $request = $this->http->withOptions(['allow_redirects' => false])->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()->withHeaders($headers);
            if ($bearer !== null) {
                $request = $request->withToken($bearer);
            }
            if ($form) {
                $request = $request->asForm();
            }

            return $request->post($url, $body === [] ? (object) [] : $body);
        } catch (Throwable) {
            throw ChannelException::sendFailed();
        }
    }
}
