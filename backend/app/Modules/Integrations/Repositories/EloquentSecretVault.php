<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Repositories;

use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\DTO\SecretMeta;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationSecret;
use Illuminate\Database\Eloquent\Builder;
use SensitiveParameter;

/** Secrets in integration_secrets, encrypted with APP_KEY by the model's "encrypted" cast. */
final class EloquentSecretVault implements SecretVault
{
    public function get(string $integrationKey, string $name): ?string
    {
        return $this->query($integrationKey)->where('name', $name)->first()?->value;
    }

    public function put(string $integrationKey, string $name, #[SensitiveParameter] string $value, ?int $updatedBy = null): void
    {
        $integration = Integration::query()->firstOrCreate(['key' => $integrationKey]);

        IntegrationSecret::query()->updateOrCreate(
            ['integration_id' => $integration->id, 'name' => $name],
            ['value' => $value, 'updated_by' => $updatedBy],
        );
    }

    public function forget(string $integrationKey, string $name): void
    {
        $this->query($integrationKey)->where('name', $name)->delete();
    }

    public function describe(string $integrationKey): array
    {
        $meta = [];
        foreach ($this->query($integrationKey)->get() as $secret) {
            $meta[$secret->name] = new SecretMeta(true, $secret->updated_at, SecretMeta::mask($secret->value));
        }

        return $meta;
    }

    /** @return Builder<IntegrationSecret> */
    private function query(string $integrationKey): Builder
    {
        return IntegrationSecret::query()
            ->whereIn('integration_id', Integration::query()->select('id')->where('key', $integrationKey));
    }
}
