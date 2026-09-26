<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Repositories;

use App\Modules\MailAgent\Contracts\MailLogRepository;
use App\Modules\MailAgent\Enums\MailOutcome;
use App\Modules\MailAgent\Models\MailMessage;
use App\Modules\MailAgent\Models\MailSyncRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class EloquentMailLogRepository implements MailLogRepository
{
    public function isProcessed(string $gmailId): bool
    {
        return MailMessage::query()->where('gmail_id', $gmailId)->exists();
    }

    public function record(array $attributes): MailMessage
    {
        return MailMessage::query()->firstOrCreate(['gmail_id' => $attributes['gmail_id']], $attributes);
    }

    public function recent(int $limit): Collection
    {
        return MailMessage::query()->orderByDesc('received_at')->orderByDesc('id')->limit($limit)->get();
    }

    public function processedSince(Carbon $since): int
    {
        return MailMessage::query()->where('created_at', '>=', $since)->count();
    }

    public function unknownFrom(string $email, int $limit): array
    {
        return array_values(MailMessage::query()
            ->where('sender', $email)->where('outcome', MailOutcome::Unknown->value)
            ->orderBy('received_at')->orderBy('id')->limit($limit)
            ->pluck('gmail_id')->map(static fn (mixed $id): string => (string) $id)->all());
    }

    public function latestFrom(string $email): ?string
    {
        $id = MailMessage::query()->where('sender', $email)->orderByDesc('received_at')->orderByDesc('id')->value('gmail_id');

        return is_string($id) ? $id : null;
    }

    public function updateUnknown(string $gmailId, array $attributes): bool
    {
        return MailMessage::query()->where('gmail_id', $gmailId)->where('outcome', MailOutcome::Unknown->value)->update($attributes) === 1;
    }

    public function startRun(string $trigger, ?int $userId, Carbon $at): MailSyncRun
    {
        return MailSyncRun::query()->create(['trigger' => $trigger, 'user_id' => $userId, 'started_at' => $at]);
    }

    public function finishRun(MailSyncRun $run, array $counts, ?int $cursorMs, ?string $error, Carbon $at): MailSyncRun
    {
        $run->fill(['finished_at' => $at, 'counts' => $counts, 'cursor_ms' => $cursorMs, 'error' => $error])->save();

        return $run;
    }

    public function lastRun(): ?MailSyncRun
    {
        return MailSyncRun::query()->orderByDesc('id')->first();
    }

    public function cursor(): ?int
    {
        $max = MailSyncRun::query()->whereNotNull('finished_at')->whereNull('error')->max('cursor_ms');

        return is_numeric($max) ? (int) $max : null;
    }
}
