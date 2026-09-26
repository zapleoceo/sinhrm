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
 * WhatsApp Cloud API (Meta). Check: read-only GET /{phone_number_id}?fields=id with the access token (header, not URL).
 * app_secret signs webhooks (X-Hub-Signature-256), verify_token answers the GET subscription handshake.
 */
final class WhatsappCloudDefinition extends AbstractDefinition implements ConnectionChecker
{
    public const string GRAPH_API = 'https://graph.facebook.com/v21.0';

    public const string ID_PATTERN = '/^\d{5,32}$/';

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(private readonly Http $http, private readonly OutboundUrlGuard $guard) {}

    public function key(): string
    {
        return 'whatsapp_cloud';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Messengers;
    }

    public function fields(): array
    {
        return [
            FieldSpec::text('phone_number_id', required: true),
            FieldSpec::text('waba_id', required: true),
            FieldSpec::secret('access_token'),
            FieldSpec::secret('app_secret', required: false),
            FieldSpec::secret('verify_token', required: false),
        ];
    }

    public function check(IntegrationConfig $config): CheckResult
    {
        $phoneId = (string) $config->setting('phone_number_id');
        if (preg_match(self::ID_PATTERN, $phoneId) !== 1) {
            return CheckResult::error('invalid_url');
        }
        $url = self::GRAPH_API.'/'.$phoneId.'?fields=id';
        $blocked = $this->guard->check($url);
        if ($blocked !== null) {
            return CheckResult::error($blocked);
        }

        try {
            $response = $this->http->withOptions(['allow_redirects' => false])->timeout(self::TIMEOUT_SECONDS)
                ->withToken((string) $config->secret('access_token'))->acceptJson()->get($url);
        } catch (Throwable) {
            return CheckResult::error('connection_failed');
        }

        if ($response->successful() && $response->json('id') === $phoneId) {
            return CheckResult::connected();
        }

        // Graph API error 190 = invalid/expired access token.
        return CheckResult::error($response->status() === 401 || $response->json('error.code') === 190
            ? 'unauthorized'
            : 'http_'.$response->status());
    }
}
