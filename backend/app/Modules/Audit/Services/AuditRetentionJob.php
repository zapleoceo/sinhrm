<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Contracts\AuditLogRepository;
use App\Modules\Core\Contracts\ScheduledJob;
use Illuminate\Support\Carbon;

/** "audit.retention": deletes audit rows older than RETENTION_DAYS. Idempotent: a second run finds nothing. */
final readonly class AuditRetentionJob implements ScheduledJob
{
    public const int RETENTION_DAYS = 365;

    public function __construct(private AuditLogRepository $repository) {}

    public function name(): string
    {
        return 'audit.retention';
    }

    public function run(Carbon $now): array
    {
        return ['deleted' => $this->repository->purgeOlderThan($now->copy()->subDays(self::RETENTION_DAYS))];
    }
}
