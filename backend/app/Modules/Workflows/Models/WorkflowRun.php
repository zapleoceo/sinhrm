<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Models;

use App\Models\User;
use App\Modules\People\Models\Employee;
use App\Modules\Workflows\Enums\RunStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $template_id
 * @property int $employee_id
 * @property string $template_name
 * @property Carbon $anchor_date
 * @property RunStatus $status
 * @property int|null $started_by
 * @property string|null $trigger_key
 * @property int|null $parent_run_id
 * @property int $depth
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read WorkflowTemplate $template
 * @property-read User|null $starter
 * @property-read Collection<int, WorkflowRunStep> $steps
 */
final class WorkflowRun extends Model
{
    protected $fillable = [
        'template_id', 'employee_id', 'template_name', 'anchor_date', 'status', 'started_by', 'trigger_key',
        'parent_run_id', 'depth', 'completed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'running', 'depth' => 0];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<WorkflowTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return HasMany<WorkflowRunStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowRunStep::class, 'run_id')->orderBy('position')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'anchor_date' => 'date',
            'completed_at' => 'datetime',
            'depth' => 'integer',
        ];
    }
}
