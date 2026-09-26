<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Repositories;

use App\Modules\Pulse\Contracts\MoodRepository;
use App\Modules\Pulse\Models\MoodCheckin;
use App\Modules\Pulse\Models\MoodSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class EloquentMoodRepository implements MoodRepository
{
    public function settings(): MoodSetting
    {
        return MoodSetting::query()->orderBy('id')->first() ?? MoodSetting::query()->create(MoodSetting::DEFAULTS);
    }

    public function saveSettings(array $attributes): MoodSetting
    {
        $settings = $this->settings();
        $settings->fill($attributes)->save();

        return $settings;
    }

    public function forDay(int $employeeId, Carbon $day): ?MoodCheckin
    {
        return MoodCheckin::query()->where('employee_id', $employeeId)->whereDate('day', $day->toDateString())->first();
    }

    public function upsert(int $employeeId, Carbon $day, int $score, ?string $comment): MoodCheckin
    {
        $checkin = $this->forDay($employeeId, $day) ?? new MoodCheckin(['employee_id' => $employeeId, 'day' => $day->toDateString()]);
        $checkin->fill(['score' => $score, 'comment' => $comment])->save();

        return $checkin;
    }

    public function history(int $employeeId, Carbon $from, Carbon $to): Collection
    {
        return MoodCheckin::query()->where('employee_id', $employeeId)
            ->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<=', $to->toDateString())
            ->orderByDesc('day')->get();
    }

    public function between(?array $employeeIds, Carbon $from, Carbon $to): array
    {
        return MoodCheckin::query()
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $employeeIds ?? []))
            ->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<=', $to->toDateString())
            ->orderBy('day')->orderBy('id')->get(['employee_id', 'day', 'score', 'comment'])
            ->map(static fn (MoodCheckin $c): array => [
                'employee_id' => $c->employee_id,
                'day' => $c->day->toDateString(),
                'score' => $c->score,
                'comment' => $c->comment,
            ])->values()->all();
    }
}
