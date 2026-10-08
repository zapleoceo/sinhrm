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
 * AI Broker (own gateway to LLM providers) and the AI settings of SinHRM: capability, model, daily caps and
 * per-purpose switches (read by App\Modules\Ai\Support\AiSettingsReader, docs/modules/ai.md).
 * The check calls ONLY the public GET /v1/health without the project key; real jobs are sent by the Ai module
 * (AiBrokerProvider) and only while the global AI switch is on.
 */
final class AiBrokerDefinition extends AbstractHttpCheckedDefinition
{
    public const string DEFAULT_BASE_URL = 'https://aib.zapleo.com';

    public const string DEFAULT_CAPABILITY = 'chat:fast';

    /** Lane of the helper «Стік» (native tools): see fields(). */
    public const string ASSISTANT_CAPABILITY = 'chat:fast';

    /** Broker capabilities allowed for SinHRM tasks (broker docs/api.md). */
    public const array CAPABILITIES = ['chat:fast', 'chat:smart', 'chat:sales', 'structured'];

    public const int DEFAULT_MAX_REQUESTS = 200;

    public const string DEFAULT_CAP_USD = '2';

    /** Options of the on/off selects (per-purpose switches). */
    public const array SWITCH = ['on', 'off'];

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
            FieldSpec::select('capability_vacancy_text', self::CAPABILITIES, default: self::DEFAULT_CAPABILITY),
            // Helper lane: chat:fast — with tool emulation (native_tools off) any provider can serve it, and this lane has the
            // most live free providers. Measured on prod 28.09: chat:smart turns never left the broker queue (gemini on
            // cooldown, anthropic dead), chat:fast answered in 11 s.
            FieldSpec::select('capability_assistant_chat', self::CAPABILITIES, default: self::ASSISTANT_CAPABILITY),
            // Empty by default (owner decision): the request then carries no model and the broker picks it for the capability.
            FieldSpec::text('model'),
            FieldSpec::text('max_requests_per_day', default: (string) self::DEFAULT_MAX_REQUESTS),
            FieldSpec::text('daily_cap_usd', default: self::DEFAULT_CAP_USD),
            FieldSpec::select('ai_script_evaluation', self::SWITCH, default: 'on'),
            FieldSpec::select('ai_mail_classification', self::SWITCH, default: 'on'),
            FieldSpec::select('ai_candidate_screening', self::SWITCH, default: 'on'),
            FieldSpec::select('ai_vacancy_text', self::SWITCH, default: 'on'),
            FieldSpec::select('ai_assistant_chat', self::SWITCH, default: 'on'),
            FieldSpec::select('ai_screening_auto', self::SWITCH, default: 'off'),
            // off = tools of the helper go as strict JSON (any provider can answer); on = broker native tools, served
            // only by openai/anthropic/gemini/mistral keys — the helper stalls when none of them is alive.
            FieldSpec::select('native_tools', self::SWITCH, default: 'off'),
        ];
    }

    public function check(IntegrationConfig $config): CheckResult
    {
        $url = rtrim($config->setting('base_url') ?? self::DEFAULT_BASE_URL, '/').'/v1/health';
        $response = $this->probe($url, static fn (PendingRequest $r): Response => $r->get($url));
        if ($response instanceof CheckResult) {
            return $response;
        }

        return $response->successful()
            ? CheckResult::connected()
            : CheckResult::error('http_'.$response->status());
    }
}
