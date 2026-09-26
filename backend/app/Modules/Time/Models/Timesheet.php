<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use App\Models\User;
use App\Modules\People\Models\Employee;
use App\Modules\Time\Enums\TimesheetStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One employee's week (Monday start).
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $week_start
 * @property TimesheetStatus $status
 * @property string $expected_hours decimal
 * @property string $worked_hours decimal
 * @property string $overtime_hours decimal
 * @property Carbon|null $submitted_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read User|null $decider
 * @property-read Collection<int, TimeEntry> $entries
 */
final class Timesheet extends Model
{
    protected $fillable = [
        'employee_id', 'week_start', 'status', 'expected_hours', 'worked_hours', 'overtime_hours', 'submitted_at',
        'decided_by', 'decided_at', 'decision_comment',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'expected_hours' => 0, 'worked_hours' => 0, 'overtime_hours' => 0];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<TimeEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TimeEntry::class)->orderBy('date')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TimesheetStatus::class,
            'week_start' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
