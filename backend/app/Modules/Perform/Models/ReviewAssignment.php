<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use App\Modules\People\Models\Employee;
use App\Modules\Perform\Enums\AssignmentStatus;
use App\Modules\Perform\Enums\ReviewType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One reviewer's form about one subject in a cycle.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $subject_employee_id
 * @property int $reviewer_employee_id
 * @property ReviewType $type
 * @property AssignmentStatus $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ReviewCycle $cycle
 * @property-read Employee $subject
 * @property-read Employee $reviewer
 * @property-read Collection<int, ReviewAnswer> $answers
 */
final class ReviewAssignment extends Model
{
    protected $fillable = ['cycle_id', 'subject_employee_id', 'reviewer_employee_id', 'type', 'status', 'submitted_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /** @return BelongsTo<ReviewCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'cycle_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'subject_employee_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_employee_id');
    }

    /** @return HasMany<ReviewAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ReviewAnswer::class, 'assignment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => ReviewType::class, 'status' => AssignmentStatus::class, 'submitted_at' => 'datetime'];
    }
}
