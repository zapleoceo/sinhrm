<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Models;

use App\Models\User;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Enums\HalfDay;
use App\Modules\TimeOff\Enums\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property HalfDay $half_day
 * @property string $days decimal
 * @property string|null $comment
 * @property LeaveRequestStatus $status
 * @property bool $balance_override
 * @property int|null $approver_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_comment
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read LeaveType $leaveType
 * @property-read User|null $approver
 */
final class LeaveRequest extends Model
{
    protected $fillable = [
        'employee_id', 'leave_type_id', 'starts_on', 'ends_on', 'half_day', 'days', 'comment', 'status',
        'balance_override', 'approver_id', 'decided_at', 'decision_comment', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'half_day' => 'none', 'balance_override' => false];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function daysValue(): float
    {
        return (float) $this->days;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'half_day' => HalfDay::class,
            'status' => LeaveRequestStatus::class,
            'days' => 'decimal:2',
            'balance_override' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }
}
