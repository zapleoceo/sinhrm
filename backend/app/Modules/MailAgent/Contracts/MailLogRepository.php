<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Contracts;

use App\Modules\MailAgent\Models\MailMessage;
use App\Modules\MailAgent\Models\MailSyncRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Processed messages and sync runs of the mail agent. */
interface MailLogRepository
{
    public function isProcessed(string $gmailId): bool;

    /** @param  array<string, mixed>  $attributes */
    public function record(array $attributes): MailMessage;

    /** @return Collection<int, MailMessage> newest first */
    public function recent(int $limit): Collection;

    public function processedSince(Carbon $since): int;

    public function startRun(string $trigger, ?int $userId, Carbon $at): MailSyncRun;

    /** @param  array<string, int>  $counts */
    public function finishRun(MailSyncRun $run, array $counts, ?int $cursorMs, ?string $error, Carbon $at): MailSyncRun;

    public function lastRun(): ?MailSyncRun;

    /** Highest cursor of successful runs (ms), or null before the first one. */
    public function cursor(): ?int;
}
