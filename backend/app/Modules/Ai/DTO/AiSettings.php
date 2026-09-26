<?php

declare(strict_types=1);

namespace App\Modules\Ai\DTO;

use App\Modules\Ai\Enums\AiPurpose;
use SensitiveParameter;

/** Runtime AI settings from the ai_broker integration (settings + the project key from the vault). */
final readonly class AiSettings
{
    /**
     * @param  array<string, string>  $capabilities  purpose value → broker capability
     * @param  array<string, bool>  $purposes  purpose value → switched on
     */
    public function __construct(
        public string $baseUrl,
        #[SensitiveParameter] public ?string $projectKey,
        public bool $integrationOn,
        /** Default capability (the test prompt, and any purpose without its own). */
        public string $capability,
        public array $capabilities,
        /** null = the broker picks the model of the capability. */
        public ?string $model,
        public int $maxRequestsPerDay,
        public float $maxCostPerDay,
        public array $purposes,
        public bool $autoScreening,
    ) {}

    public function configured(): bool
    {
        return $this->integrationOn && $this->projectKey !== null && $this->projectKey !== '';
    }

    public function capabilityFor(AiPurpose $purpose): string
    {
        return $this->capabilities[$purpose->value] ?? $this->capability;
    }

    public function purposeEnabled(AiPurpose $purpose): bool
    {
        return $purpose->settingName() === null || ($this->purposes[$purpose->value] ?? false);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'project_key' => $this->projectKey === null ? null : '[set]',
            'capability' => $this->capability,
            'model' => $this->model,
        ];
    }
}
