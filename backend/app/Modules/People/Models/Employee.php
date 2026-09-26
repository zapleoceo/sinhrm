<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use App\Models\User;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\People\Database\Factories\EmployeeFactory;
use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person working in the company (with or without a SinHRM login).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $full_name
 * @property string|null $work_email
 * @property string|null $phone
 * @property string|null $avatar_url
 * @property Carbon|null $birth_date
 * @property string|null $personal_email
 * @property string|null $address
 * @property string|null $emergency_contact
 * @property array<string, mixed>|null $custom_fields
 * @property Carbon $hired_at
 * @property Carbon|null $fired_at
 * @property Carbon|null $anonymized_at personal data erased after offboarding (Privacy)
 * @property string|null $termination_reason
 * @property EmployeeStatus $status
 * @property EmploymentType $employment_type
 * @property array<string, mixed>|null $work_schedule
 * @property int|null $branch_id
 * @property int|null $department_id
 * @property int|null $position_id
 * @property int|null $manager_id
 * @property int|null $candidate_id
 * @property int|null $application_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $reports_count
 * @property-read User|null $user
 * @property-read Branch|null $branch
 * @property-read Department|null $department
 * @property-read Position|null $position
 * @property-read Employee|null $manager
 * @property-read Collection<int, Employee> $reports
 */
final class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'full_name', 'work_email', 'phone', 'avatar_url', 'birth_date', 'personal_email', 'address',
        'emergency_contact', 'custom_fields', 'hired_at', 'fired_at', 'termination_reason', 'status', 'employment_type',
        'work_schedule', 'branch_id', 'department_id', 'position_id', 'manager_id', 'candidate_id', 'application_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'employment_type' => 'full_time'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /** @return HasMany<Employee, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function isTerminated(): bool
    {
        return $this->status === EmployeeStatus::Terminated;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'employment_type' => EmploymentType::class,
            'birth_date' => 'date',
            'hired_at' => 'date',
            'fired_at' => 'date',
            'custom_fields' => 'array',
            'work_schedule' => 'array',
            'anonymized_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }
}
