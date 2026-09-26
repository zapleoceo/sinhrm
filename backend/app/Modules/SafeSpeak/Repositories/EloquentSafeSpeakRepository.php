<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Repositories;

use App\Modules\SafeSpeak\Contracts\SafeSpeakRepository;
use App\Modules\SafeSpeak\Models\SafeSpeakMessage;
use App\Modules\SafeSpeak\Models\SafeSpeakReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentSafeSpeakRepository implements SafeSpeakRepository
{
    public function create(array $attributes, string $body): SafeSpeakReport
    {
        return DB::transaction(function () use ($attributes, $body): SafeSpeakReport {
            $report = SafeSpeakReport::query()->create($attributes);
            SafeSpeakMessage::query()->create([
                'report_id' => $report->id,
                'author' => SafeSpeakMessage::REPORTER,
                'body' => $body,
                'created_on' => $attributes['created_on'],
            ]);

            return $report;
        });
    }

    public function findByCodeHash(string $hash): ?SafeSpeakReport
    {
        return SafeSpeakReport::query()->where('access_code_hash', $hash)->first();
    }

    public function find(int $id): ?SafeSpeakReport
    {
        return SafeSpeakReport::query()->find($id);
    }

    public function inbox(?string $status, int $limit): Collection
    {
        return SafeSpeakReport::query()
            ->withCount('messages')
            ->when($status !== null, static fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function addMessage(SafeSpeakReport $report, string $author, ?int $handlerId, string $body, string $today): void
    {
        DB::transaction(static function () use ($report, $author, $handlerId, $body, $today): void {
            SafeSpeakMessage::query()->create([
                'report_id' => $report->id,
                'author' => $author,
                'handler_id' => $handlerId,
                'body' => $body,
                'created_on' => $today,
            ]);
            $report->forceFill(['updated_on' => $today])->save();
        });
    }

    public function update(SafeSpeakReport $report, array $attributes): SafeSpeakReport
    {
        $report->fill($attributes)->save();

        return $report;
    }
}
