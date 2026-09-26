<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\Contracts\ConnectionChecker;
use App\Modules\Integrations\DTO\CheckResult;
use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\IntegrationGroup;
use App\Modules\Integrations\Support\OutboundUrlGuard;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Viber bot (REST Bot API). Check: read-only POST /pa/get_account_info with the token in X-Viber-Auth-Token.
 * The same token signs webhooks (X-Viber-Content-Signature = HMAC-SHA256 of the body).
 */
final class ViberDefinition extends AbstractDefinition implements ConnectionChecker
{
    public const string API = 'https://chatapi.viber.com/pa';

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(private readonly Http $http, private readonly OutboundUrlGuard $guard) {}

    public function key(): string
    {
        return 'viber';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Messengers;
    }

    public function fields(): array
    {
        return [
            FieldSpec::secret('token'),
        ];
    }

    public function check(IntegrationConfig $config): CheckResult
    {
        $url = self::API.'/get_account_info';
        $blocked = $this->guard->check($url);
        if ($blocked !== null) {
            return CheckResult::error($blocked);
        }

        try {
            $response = $this->http->withOptions(['allow_redirects' => false])->timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['X-Viber-Auth-Token' => (string) $config->secret('token')])->acceptJson()->post($url, (object) []);
        } catch (Throwable) {
            return CheckResult::error('connection_failed');
        }

        // Viber answers 200 with {status: 0} on success and a non-zero status (2 = invalid auth token) on failure.
        if ($response->successful() && $response->json('status') === 0) {
            return CheckResult::connected();
        }

        return CheckResult::error($response->successful() && $response->json('status') === 2 ? 'unauthorized' : 'http_'.$response->status());
    }
}
