<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use Illuminate\Support\Carbon;

/** "mail.sync" for POST /api/ops/jobs/run (cron every 30 min). Gmail not connected → a no-op. */
final readonly class MailSyncJob implements ScheduledJob
{
    public function __construct(private MailSyncService $sync) {}

    public function name(): string
    {
        return 'mail.sync';
    }

    public function run(Carbon $now): array
    {
        if (! $this->sync->connected()) {
            return ['skipped' => 'not_connected'];
        }
        try {
            return $this->sync->sync('cron');
        } catch (GoogleException $e) {
            return ['error' => $e->errorCode];
        }
    }
}
