<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use App\Modules\People\Models\Employee;
use App\Modules\Perform\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An individual development plan: goals and dated actions.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $title
 * @property list<array{id: string, text: string}> $goals
 * @property list<array{id: string, text: string, due_on?: string|null, done: bool}> $actions
 * @property Carbon|null $due_on
 * @property PlanStatus $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 */
final class DevelopmentPlan extends Model
{
    protected $fillable = ['employee_id', 'title', 'goals', 'actions', 'due_on', 'status', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['goals' => 'array', 'actions' => 'array', 'due_on' => 'date', 'status' => PlanStatus::class];
    }
}
