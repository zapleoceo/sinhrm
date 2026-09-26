<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use App\Modules\People\Models\Employee;
use App\Modules\Perform\Enums\ObjectiveScope;
use App\Modules\Perform\Enums\ObjectiveStatus;
use App\Modules\Perform\Enums\ObjectiveVisibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An OKR objective with key results; aligned to a parent objective (the tree).
 *
 * @property int $id
 * @property ObjectiveScope $scope
 * @property int|null $owner_employee_id
 * @property int|null $department_id
 * @property int|null $branch_id
 * @property string $period
 * @property string $title
 * @property string|null $description
 * @property list<array<string, mixed>> $key_results
 * @property int $progress
 * @property ObjectiveStatus $status
 * @property int|null $parent_objective_id
 * @property ObjectiveVisibility $visibility
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee|null $owner
 * @property-read Collection<int, ObjectiveCheckin> $checkins
 */
final class Objective extends Model
{
    protected $fillable = [
        'scope', 'owner_employee_id', 'department_id', 'branch_id', 'period', 'title', 'description', 'key_results',
        'progress', 'status', 'parent_objective_id', 'visibility', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'visibility' => 'public', 'progress' => 0];

    /** @return BelongsTo<Employee, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    /** @return HasMany<ObjectiveCheckin, $this> */
    public function checkins(): HasMany
    {
        return $this->hasMany(ObjectiveCheckin::class)->orderByDesc('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => ObjectiveScope::class,
            'status' => ObjectiveStatus::class,
            'visibility' => ObjectiveVisibility::class,
            'key_results' => 'array',
            'progress' => 'integer',
        ];
    }
}
