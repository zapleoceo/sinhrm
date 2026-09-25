<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

use App\Modules\Integrations\Enums\LogLevel;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationLog;
use Illuminate\Support\Collection;

interface IntegrationRepository
{
    /** @return Collection<string, Integration> keyed by integration key */
    public function allByKey(): Collection;

    public function find(string $key): ?Integration;

    /** Rows are created lazily: an integration without a row is "off" with empty settings. */
    public function findOrCreate(string $key): Integration;

    public function save(Integration $integration): void;

    /** @param  array<string, mixed>  $context  names/ids only — never secret values */
    public function log(Integration $integration, LogLevel $level, string $message, array $context = []): void;

    /** @return Collection<int, IntegrationLog> newest first */
    public function recentLogs(string $key, int $limit): Collection;
}
