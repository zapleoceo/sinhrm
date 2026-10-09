<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\CheckResult;
use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\IntegrationGroup;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * WhatsApp Cloud API (Meta). Check: read-only GET /{phone_number_id}?fields=id with the access token (header, not URL).
 * app_secret signs webhooks (X-Hub-Signature-256), verify_token answers the GET subscription handshake.
 */
final class WhatsappCloudDefinition extends AbstractHttpCheckedDefinition
{
    public const string GRAPH_API = 'https://graph.facebook.com/v21.0';

    public const string ID_PATTERN = '/^\d{5,32}$/';

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
        $response = $this->probe($url, static fn (PendingRequest $r): Response => $r
            ->withToken((string) $config->secret('access_token'))->get($url));
        if ($response instanceof CheckResult) {
            return $response;
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
