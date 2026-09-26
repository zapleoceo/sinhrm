<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Models;

use App\Models\User;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\HiringRequests\Enums\ApprovalStatus;
use App\Modules\HiringRequests\Enums\HiringPriority;
use App\Modules\HiringRequests\Enums\HiringReason;
use App\Modules\HiringRequests\Enums\HiringRequestStatus;
use App\Modules\People\Models\Employee;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A request to hire for a position (tz2).
 *
 * @property int $id
 * @property string $title
 * @property int $branch_id
 * @property int|null $department_id
 * @property int|null $position_id
 * @property int $headcount
 * @property HiringReason $reason
 * @property int|null $replaced_employee_id
 * @property Carbon|null $desired_start_date
 * @property string|null $salary_min decimal
 * @property string|null $salary_max decimal
 * @property string|null $currency
 * @property string|null $requirements
 * @property HiringPriority $priority
 * @property array<string, mixed>|null $extra
 * @property HiringRequestStatus $status
 * @property int|null $requester_id
 * @property int|null $recruiter_id
 * @property int|null $vacancy_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Department|null $department
 * @property-read Position|null $position
 * @property-read Employee|null $replacedEmployee
 * @property-read User|null $requester
 * @property-read User|null $recruiter
 * @property-read Vacancy|null $vacancy
 * @property-read Collection<int, HiringApproval> $approvals
 */
final class HiringRequest extends Model
{
    protected $fillable = [
        'title', 'branch_id', 'department_id', 'position_id', 'headcount', 'reason', 'replaced_employee_id',
        'desired_start_date', 'salary_min', 'salary_max', 'currency', 'requirements', 'priority', 'extra', 'status',
        'requester_id', 'recruiter_id', 'vacancy_id', 'submitted_at', 'decided_at', 'closed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'priority' => 'normal', 'headcount' => 1];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function replacedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'replaced_employee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruiter_id');
    }

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    /** @return HasMany<HiringApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(HiringApproval::class)->orderBy('position');
    }

    /** The step waiting for a decision now (loaded approvals). */
    public function currentApproval(): ?HiringApproval
    {
        return $this->approvals->first(static fn (HiringApproval $a): bool => $a->status === ApprovalStatus::Pending);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => HiringReason::class,
            'priority' => HiringPriority::class,
            'status' => HiringRequestStatus::class,
            'extra' => 'array',
            'headcount' => 'integer',
            'desired_start_date' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
