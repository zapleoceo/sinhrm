<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Models;

use App\Modules\Directory\Models\Branch;
use App\Modules\TimeOff\Enums\AccrualMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $leave_type_id
 * @property int|null $branch_id null = company default
 * @property AccrualMode $accrual_mode
 * @property string $annual_days decimal
 * @property string|null $carry_over_max decimal; null = unlimited carry over
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeaveType $leaveType
 * @property-read Branch|null $branch
 */
final class LeavePolicy extends Model
{
    protected $fillable = ['leave_type_id', 'branch_id', 'accrual_mode', 'annual_days', 'carry_over_max', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = ['accrual_mode' => 'yearly_upfront', 'active' => true];

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function annualDays(): float
    {
        return (float) $this->annual_days;
    }

    public function carryOverMax(): ?float
    {
        return $this->carry_over_max === null ? null : (float) $this->carry_over_max;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['accrual_mode' => AccrualMode::class, 'active' => 'boolean', 'annual_days' => 'decimal:2', 'carry_over_max' => 'decimal:2'];
    }
}
