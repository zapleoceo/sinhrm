<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Repositories;

use App\Modules\Integrations\Contracts\IntegrationRepository;
use App\Modules\Integrations\Enums\LogLevel;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationLog;
use Illuminate\Support\Collection;

final class EloquentIntegrationRepository implements IntegrationRepository
{
    public function allByKey(): Collection
    {
        return Integration::query()->get()->keyBy('key');
    }

    public function find(string $key): ?Integration
    {
        return Integration::query()->where('key', $key)->first();
    }

    public function findOrCreate(string $key): Integration
    {
        return Integration::query()->firstOrCreate(['key' => $key]);
    }

    public function save(Integration $integration): void
    {
        $integration->save();
    }

    public function log(Integration $integration, LogLevel $level, string $message, array $context = []): void
    {
        IntegrationLog::query()->create([
            'integration_id' => $integration->id,
            'level' => $level,
            'message' => mb_substr($message, 0, 255),
            'context' => $context === [] ? null : $context,
        ]);
    }

    public function recentLogs(string $key, int $limit): Collection
    {
        return IntegrationLog::query()
            ->whereIn('integration_id', Integration::query()->select('id')->where('key', $key))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
