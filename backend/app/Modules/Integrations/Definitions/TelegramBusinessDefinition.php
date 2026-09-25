<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\Contracts\ConnectionChecker;
use App\Modules\Integrations\DTO\CheckResult;
use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\IntegrationGroup;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Telegram bot (business account). Check: read-only getMe.
 * The request URL contains the token, so neither the URL nor exception texts are ever logged or returned.
 */
final class TelegramBusinessDefinition extends AbstractDefinition implements ConnectionChecker
{
    private const string API = 'https://api.telegram.org';

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(private readonly Http $http) {}

    public function key(): string
    {
        return 'telegram_business';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Messengers;
    }

    public function fields(): array
    {
        return [FieldSpec::secret('bot_token')];
    }

    public function check(IntegrationConfig $config): CheckResult
    {
        $token = $config->secret('bot_token');
        if ($token === null) {
            return CheckResult::error('missing_secret:bot_token');
        }

        try {
            $response = $this->http->timeout(self::TIMEOUT_SECONDS)->acceptJson()->get(self::API.'/bot'.$token.'/getMe');
        } catch (ConnectionException) {
            return CheckResult::error('connection_failed');
        }

        if ($response->successful() && $response->json('ok') === true) {
            return CheckResult::connected();
        }

        return CheckResult::error($response->status() === 401 ? 'unauthorized' : 'http_'.$response->status());
    }
}
