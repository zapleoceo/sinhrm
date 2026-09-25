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
 * AI Broker (own gateway to LLM providers).
 * The check calls ONLY the public GET /v1/health without the project key: chat/jobs endpoints are
 * forbidden until the owner approves models and prompts (see AI policy).
 */
final class AiBrokerDefinition extends AbstractDefinition implements ConnectionChecker
{
    public const string DEFAULT_BASE_URL = 'https://aib.zapleo.com';

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(private readonly Http $http) {}

    public function key(): string
    {
        return 'ai_broker';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Ai;
    }

    public function fields(): array
    {
        return [
            FieldSpec::url('base_url', required: true, default: self::DEFAULT_BASE_URL),
            FieldSpec::secret('project_key'),
            FieldSpec::text('daily_cap_usd'),
        ];
    }

    public function check(IntegrationConfig $config): CheckResult
    {
        $base = rtrim($config->setting('base_url') ?? self::DEFAULT_BASE_URL, '/');

        try {
            $response = $this->http->timeout(self::TIMEOUT_SECONDS)->acceptJson()->get($base.'/v1/health');
        } catch (ConnectionException) {
            return CheckResult::error('connection_failed');
        }

        return $response->successful()
            ? CheckResult::connected()
            : CheckResult::error('http_'.$response->status());
    }
}
