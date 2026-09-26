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
 * AI Broker (own gateway to LLM providers) and the AI settings of SinHRM: capability, model, daily caps and
 * per-purpose switches (read by App\Modules\Ai\Support\AiSettingsReader, docs/modules/ai.md).
 * The check calls ONLY the public GET /v1/health without the project key; real jobs are sent by the Ai module
 * (AiBrokerProvider) and only while the global AI switch is on.
 */
final class AiBrokerDefinition extends AbstractDefinition implements ConnectionChecker
{
    public const string DEFAULT_BASE_URL = 'https://aib.zapleo.com';

    public const string DEFAULT_CAPABILITY = 'chat:fast';

    /** Broker capabilities allowed for SinHRM tasks (broker docs/api.md). */
    public const array CAPABILITIES = ['chat:fast', 'chat:smart', 'chat:sales', 'structured'];

    public const int DEFAULT_MAX_REQUESTS = 200;

    public const string DEFAULT_CAP_USD = '2';

    /** Options of the on/off selects (per-purpose switches). */
    public const array SWITCH = ['on', 'off'];

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(private readonly Http $http, private readonly OutboundUrlGuard $guard) {}

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
            // Broker lane per purpose: chat:fast after experiment round 1 (sales/smart timed out); final after round 2.
            FieldSpec::select('capability', self::CAPABILITIES, default: self::DEFAULT_CAPABILITY),
            FieldSpec::select('capability_script_evaluation', self::CAPABILITIES, default: self::DEFAULT_CAPABILITY),
            FieldSpec::select('capability_mail_classification', self::CAPABILITIES, default: self::DEFAULT_CAPABILITY),
            FieldSpec::select('capability_candidate_screening', self::CAPABILITIES, default: self::DEFAULT_CAPABILITY),
            // Empty by default (owner decision): the request then carries no model and the broker picks it for the capability.
            FieldSpec::text('model'),
            FieldSpec::text('max_requests_per_day', default: (string) self::DEFAULT_MAX_REQUESTS),
            FieldSpec::text('daily_cap_usd', default: self::DEFAULT_CAP_USD),
            FieldSpec::select('ai_script_evaluation', self::SWITCH, default: 'on'),
            FieldSpec::select('ai_mail_classification', self::SWITCH, default: 'on'),
            FieldSpec::select('ai_candidate_screening', self::SWITCH, default: 'on'),
            FieldSpec::select('ai_screening_auto', self::SWITCH, default: 'off'),
        ];
    }

    public function check(IntegrationConfig $config): CheckResult
    {
        $url = rtrim($config->setting('base_url') ?? self::DEFAULT_BASE_URL, '/').'/v1/health';
        $blocked = $this->guard->check($url);
        if ($blocked !== null) {
            return CheckResult::error($blocked);
        }

        try {
            $response = $this->http->withOptions(['allow_redirects' => false])->timeout(self::TIMEOUT_SECONDS)->acceptJson()->get($url);
        } catch (Throwable) {
            // Never the exception text: it may contain the request URL.
            return CheckResult::error('connection_failed');
        }

        return $response->successful()
            ? CheckResult::connected()
            : CheckResult::error('http_'.$response->status());
    }
}
