<?php

declare(strict_types=1);

namespace App\Modules\Integrations\DTO;

/**
 * Everything a connection checker needs: non-secret settings (defaults applied) and decrypted secrets.
 * Lives only for the duration of a check; never serialized, logged or returned.
 */
final readonly class IntegrationConfig
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $secrets
     */
    public function __construct(
        public string $key,
        public array $settings,
        private array $secrets,
    ) {}

    public function setting(string $name): ?string
    {
        $value = $this->settings[$name] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function secret(string $name): ?string
    {
        return $this->secrets[$name] ?? null;
    }

    /** @return list<string> all secret values, used to scrub error messages */
    public function secretValues(): array
    {
        return array_values(array_filter($this->secrets, static fn (string $v): bool => $v !== ''));
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['key' => $this->key, 'settings' => $this->settings, 'secrets' => array_keys($this->secrets)];
    }
}
