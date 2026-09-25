<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

use App\Modules\Integrations\DTO\SecretMeta;

/**
 * The ONLY place that reads decrypted integration secrets. Other modules get tokens through this
 * interface (DI), never from the table. Values are encrypted with APP_KEY at rest.
 */
interface SecretVault
{
    public function get(string $integrationKey, string $name): ?string;

    public function put(string $integrationKey, string $name, string $value, ?int $updatedBy = null): void;

    public function forget(string $integrationKey, string $name): void;

    /**
     * Masked metadata of the stored secrets, keyed by secret name.
     *
     * @return array<string, SecretMeta>
     */
    public function describe(string $integrationKey): array;
}
