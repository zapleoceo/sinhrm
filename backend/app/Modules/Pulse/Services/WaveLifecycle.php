<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Modules\Pulse\Contracts\SurveyRepository;
use App\Modules\Pulse\Enums\WaveSchedule;
use App\Modules\Pulse\Enums\WaveStatus;
use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Wave state changes shared by the admin and the "pulse.tick" job: create (with a fresh salt), open, close.
 * Closing wipes the salt (respondent hashes can no longer be recomputed for anyone), stores the audience snapshot
 * (WaveMembership: who was asked, never who answered) and, for a recurring schedule
 * of an active survey, creates the next wave once: same audience and settings, start = previous start + period
 * (never before the previous end), same duration.
 */
final readonly class WaveLifecycle
{
    public function __construct(private SurveyRepository $surveys, private WaveMembership $membership) {}

    public static function salt(): string
    {
        return Str::random(48);
    }

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes, Carbon $now): SurveyWave
    {
        return $this->surveys->createWave($attributes + [
            'salt' => self::salt(),
            'status' => $this->initialStatus(Carbon::parse((string) $attributes['starts_at']), $now),
        ]);
    }

    public function initialStatus(Carbon $startsAt, Carbon $now): string
    {
        return $startsAt->lte($now) ? WaveStatus::Open->value : WaveStatus::Scheduled->value;
    }

    public function open(SurveyWave $wave): void
    {
        $this->surveys->updateWave($wave, ['status' => WaveStatus::Open->value]);
    }

    /** @return bool whether a successor wave was created */
    public function close(SurveyWave $wave, Carbon $now): bool
    {
        $plannedEnd = $wave->ends_at->copy();
        $this->membership->snapshot($wave);
        $this->surveys->updateWave($wave, [
            'status' => WaveStatus::Closed->value,
            'salt' => null,
            'ends_at' => $wave->ends_at->gt($now) ? $now : $wave->ends_at,
        ]);

        return $this->scheduleNext($wave, $plannedEnd, $now);
    }

    private function scheduleNext(SurveyWave $wave, Carbon $plannedEnd, Carbon $now): bool
    {
        $next = $wave->schedule->next($wave->starts_at);
        if ($next === null || $wave->schedule === WaveSchedule::Once || ! $wave->survey->active || $wave->isLifecycle()) {
            return false;
        }
        $duration = max(1, (int) $wave->starts_at->diffInSeconds($plannedEnd));
        $start = $next->lt($plannedEnd) ? $plannedEnd->copy() : $next;

        return $this->surveys->createSuccessorOnce([
            'survey_id' => $wave->survey_id,
            'parent_wave_id' => $wave->id,
            'schedule' => $wave->schedule->value,
            'audience' => $wave->audience,
            'anonymous' => $wave->anonymous,
            'min_group_size' => $wave->min_group_size,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addSeconds($duration),
            'created_by' => $wave->created_by,
            'salt' => self::salt(),
            'status' => $this->initialStatus($start, $now),
        ]) !== null;
    }
}
