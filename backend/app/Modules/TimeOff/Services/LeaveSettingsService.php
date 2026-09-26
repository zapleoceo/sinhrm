<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Models\User;
use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Models\Holiday;
use App\Modules\TimeOff\Models\LeavePolicy;
use App\Modules\TimeOff\Models\LeaveType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/** Admin settings: leave types, policies (company default + per branch), public holidays. */
final readonly class LeaveSettingsService
{
    public function __construct(
        private LeaveSettingsRepository $settings,
        private LoggerInterface $log,
    ) {}

    /** @return Collection<int, LeaveType> */
    public function types(bool $withInactive): Collection
    {
        return $this->settings->types($withInactive);
    }

    /** @throws ModelNotFoundException<LeaveType> */
    public function findType(int $id): LeaveType
    {
        return $this->settings->findType($id) ?? throw (new ModelNotFoundException)->setModel(LeaveType::class, [$id]);
    }

    /** @param  array<string, mixed>  $attributes */
    public function saveType(User $actor, ?LeaveType $type, array $attributes): LeaveType
    {
        $type = $this->settings->saveType($type, $attributes);
        $this->log->info('timeoff.type_saved', ['id' => $type->id, 'by' => $actor->id]);

        return $type;
    }

    /** @return Collection<int, LeavePolicy> */
    public function policies(): Collection
    {
        return $this->settings->policies();
    }

    /** @param  array<string, mixed>  $attributes */
    public function savePolicy(User $actor, ?LeavePolicy $policy, array $attributes): LeavePolicy
    {
        $policy = $this->settings->savePolicy($policy, $attributes);
        $this->log->info('timeoff.policy_saved', ['id' => $policy->id, 'by' => $actor->id]);

        return $policy;
    }

    /** @return Collection<int, Holiday> */
    public function holidays(?int $year, ?int $branchId): Collection
    {
        return $this->settings->holidays($year, $branchId);
    }

    /** @param  array<string, mixed>  $attributes */
    public function saveHoliday(User $actor, ?Holiday $holiday, array $attributes): Holiday
    {
        $holiday = $this->settings->saveHoliday($holiday, $attributes);
        $this->log->info('timeoff.holiday_saved', ['id' => $holiday->id, 'by' => $actor->id]);

        return $holiday;
    }

    public function deleteHoliday(User $actor, Holiday $holiday): void
    {
        $this->settings->deleteHoliday($holiday);
        $this->log->info('timeoff.holiday_deleted', ['id' => $holiday->id, 'by' => $actor->id]);
    }
}
