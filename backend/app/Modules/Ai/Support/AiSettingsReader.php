<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Modules\Ai\DTO\AiSettings;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Integrations\Definitions\AiBrokerDefinition;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Services\IntegrationConfigLoader;

/**
 * AI settings live in the ai_broker integration (admin "Інтеграції" → AI Broker): capability (default chat:fast),
 * model (empty = broker chooses), daily caps and per-purpose on/off switches. Invalid numbers fall back to defaults,
 * so a typo can never lift a cap. Read once per request/job run.
 */
final class AiSettingsReader
{
    private ?AiSettings $cached = null;

    public function __construct(
        private readonly IntegrationConfigLoader $loader,
        private readonly AiBrokerDefinition $definition,
    ) {}

    public function read(): AiSettings
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $config = $this->loader->load($this->definition);
        $purposes = [];
        $capabilities = [];
        foreach (AiPurpose::cases() as $purpose) {
            $capabilities[$purpose->value] = self::capability($config->setting($purpose->capabilitySetting()));
            $name = $purpose->settingName();
            if ($name !== null) {
                $purposes[$purpose->value] = $config->setting($name) !== 'off';
            }
        }

        return $this->cached = new AiSettings(
            baseUrl: rtrim($config->setting('base_url') ?? AiBrokerDefinition::DEFAULT_BASE_URL, '/'),
            projectKey: $config->secret('project_key'),
            integrationOn: $this->loader->status($this->definition->key()) !== IntegrationStatus::Off,
            capability: self::capability($config->setting('capability')),
            capabilities: $capabilities,
            model: self::model($config->setting('model')),
            maxRequestsPerDay: self::positiveInt($config->setting('max_requests_per_day'), AiBrokerDefinition::DEFAULT_MAX_REQUESTS),
            maxCostPerDay: self::positiveFloat($config->setting('daily_cap_usd'), (float) AiBrokerDefinition::DEFAULT_CAP_USD),
            purposes: $purposes,
            autoScreening: $config->setting('ai_screening_auto') === 'on',
        );
    }

    /** Settings may change within a long job run (tests, admin save): drop the cached copy. */
    public function forget(): void
    {
        $this->cached = null;
    }

    /** Fixed settings for this instance (the ai:experiment command: key/base URL given for one local run). */
    public function override(AiSettings $settings): void
    {
        $this->cached = $settings;
    }

    private static function capability(?string $value): string
    {
        $value = trim((string) $value);

        return in_array($value, AiBrokerDefinition::CAPABILITIES, true) ? $value : AiBrokerDefinition::DEFAULT_CAPABILITY;
    }

    private static function model(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || preg_match('~^[A-Za-z0-9._:/-]{1,128}$~', $value) !== 1 ? null : $value;
    }

    private static function positiveInt(?string $value, int $default): int
    {
        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private static function positiveFloat(?string $value, float $default): float
    {
        $value = str_replace(',', '.', trim((string) $value));

        return is_numeric($value) && (float) $value > 0 ? (float) $value : $default;
    }
}
