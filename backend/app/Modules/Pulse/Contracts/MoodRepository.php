<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Contracts;

use App\Modules\Pulse\Models\MoodCheckin;
use App\Modules\Pulse\Models\MoodSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface MoodRepository
{
    /** The settings row (created with defaults on first use). */
    public function settings(): MoodSetting;

    /** @param  array<string, mixed>  $attributes */
    public function saveSettings(array $attributes): MoodSetting;

    public function forDay(int $employeeId, Carbon $day): ?MoodCheckin;

    /** Insert or replace the employee's check-in of that day. */
    public function upsert(int $employeeId, Carbon $day, int $score, ?string $comment): MoodCheckin;

    /** @return Collection<int, MoodCheckin> own history, newest first */
    public function history(int $employeeId, Carbon $from, Carbon $to): Collection;

    /**
     * Check-ins in [from, to] (dates inclusive).
     *
     * @param  list<int>|null  $employeeIds  null = everyone
     * @return list<array{employee_id: int, day: string, score: int, comment: string|null}>
     */
    public function between(?array $employeeIds, Carbon $from, Carbon $to): array;
}
