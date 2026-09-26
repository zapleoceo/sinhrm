<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use Illuminate\Support\Carbon;

/**
 * "mail.sync" for POST /api/ops/jobs/run (cron every 30 min). Gmail not connected → a no-op. After the sync, a few
 * queued senders without an AI answer are classified (AiMailClassifier::classifyQueued, within the daily AI cap).
 */
final readonly class MailSyncJob implements ScheduledJob
{
    /** Queued senders without an AI answer classified per run (after the sync; respects the daily cap). */
    public const int AI_BACKLOG = 5;

    public function __construct(private MailSyncService $sync, private AiMailClassifier $ai) {}

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
            $counts = $this->sync->sync('cron');
        } catch (GoogleException $e) {
            return ['error' => $e->errorCode];
        }

        return $counts + ['ai_backlog' => $this->ai->classifyQueued(self::AI_BACKLOG)];
    }
}
