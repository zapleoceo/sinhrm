<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\TimeRepository;
use App\Modules\Time\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Collection;

/** Work schedules (admins): the company default (branch null) and per-branch overrides. */
final readonly class ScheduleService
{
    public function __construct(private TimeRepository $time) {}

    /** @return Collection<int, WorkSchedule> */
    public function list(): Collection
    {
        return $this->time->schedules();
    }

    /** @param  list<int>  $days */
    public function save(?int $branchId, array $days, float $hoursPerDay): WorkSchedule
    {
        $saved = $this->time->saveSchedule($branchId, $days, $hoursPerDay);

        return $saved->load('branch:id,name');
    }

    /** Removes a branch override (the company default cannot be removed, only changed). */
    public function delete(int $branchId): void
    {
        $this->time->deleteSchedule($branchId);
    }

    /** @return array<string, mixed> */
    public static function present(WorkSchedule $s): array
    {
        return [
            'id' => $s->id,
            'branch' => $s->branch === null ? null : ['id' => $s->branch->id, 'name' => $s->branch->name],
            'days' => $s->days,
            'hours_per_day' => (float) $s->hours_per_day,
        ];
    }
}
