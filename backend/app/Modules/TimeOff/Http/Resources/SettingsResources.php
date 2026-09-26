<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Resources;

use App\Modules\TimeOff\Models\Holiday;
use App\Modules\TimeOff\Models\LeavePolicy;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Models\LedgerEntry;

/** Array shapes of the TimeOff settings and the ledger (small, read-mostly entities). */
final class SettingsResources
{
    /** @return array<string, mixed> */
    public static function type(LeaveType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'code' => $type->code,
            'paid' => $type->paid,
            'unit' => $type->unit->value,
            'color' => $type->color,
            'requires_approval' => $type->requires_approval,
            'tracks_balance' => $type->tracks_balance,
            'active' => $type->active,
        ];
    }

    /** @return array<string, mixed> */
    public static function policy(LeavePolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'leave_type_id' => $policy->leave_type_id,
            'leave_type' => ['id' => $policy->leaveType->id, 'name' => $policy->leaveType->name],
            'branch_id' => $policy->branch_id,
            'branch' => $policy->branch === null ? null : ['id' => $policy->branch->id, 'name' => $policy->branch->name],
            'accrual_mode' => $policy->accrual_mode->value,
            'annual_days' => $policy->annualDays(),
            'carry_over_max' => $policy->carryOverMax(),
            'active' => $policy->active,
        ];
    }

    /** @return array<string, mixed> */
    public static function holiday(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
            'branch_id' => $holiday->branch_id,
            'branch' => $holiday->branch === null ? null : ['id' => $holiday->branch->id, 'name' => $holiday->branch->name],
        ];
    }

    /** @return array<string, mixed> */
    public static function ledger(LedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'leave_type_id' => $entry->leave_type_id,
            'delta' => (float) $entry->delta,
            'reason' => $entry->reason->value,
            'reference_id' => $entry->reference_id,
            'period' => $entry->period,
            'comment' => $entry->comment,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
