<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Support;

use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\DTO\SecretMeta;
use SensitiveParameter;

/**
 * The per-template signing key of webhook steps, kept in the SecretVault (encrypted with APP_KEY), never in the
 * template or step config. The API shows only is_set / masked metadata.
 */
final readonly class WebhookSecrets
{
    public const string VAULT_KEY = 'workflows';

    public function __construct(private SecretVault $vault) {}

    public static function name(int $templateId): string
    {
        return 'webhook_secret:'.$templateId;
    }

    public function get(int $templateId): ?string
    {
        return $this->vault->get(self::VAULT_KEY, self::name($templateId));
    }

    public function put(int $templateId, #[SensitiveParameter] string $secret, int $userId): void
    {
        $this->vault->put(self::VAULT_KEY, self::name($templateId), $secret, $userId);
    }

    public function forget(int $templateId): void
    {
        $this->vault->forget(self::VAULT_KEY, self::name($templateId));
    }

    public function describe(int $templateId): ?SecretMeta
    {
        return $this->vault->describe(self::VAULT_KEY)[self::name($templateId)] ?? null;
    }

    /** "sha256=<hex>" — HMAC-SHA256 of the exact request body. */
    public static function sign(string $body, #[SensitiveParameter] string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }
}
