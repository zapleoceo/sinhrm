<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Models;

use App\Models\User;
use App\Modules\Workflows\DTO\StepSnapshot;
use App\Modules\Workflows\Enums\RunStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $run_id
 * @property int|null $step_id
 * @property int $position
 * @property array<string, mixed> $snapshot
 * @property int|null $assignee_id
 * @property Carbon $due_at
 * @property RunStepStatus $status
 * @property Carbon|null $executed_at
 * @property int $attempts
 * @property int|null $completed_by
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $result
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkflowRun $run
 * @property-read User|null $assignee
 * @property-read User|null $completer
 */
final class WorkflowRunStep extends Model
{
    protected $fillable = [
        'run_id', 'step_id', 'position', 'snapshot', 'assignee_id', 'due_at', 'status', 'executed_at', 'attempts',
        'completed_by', 'completed_at', 'result',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    public function snapshotStep(): StepSnapshot
    {
        return StepSnapshot::fromArray($this->snapshot);
    }

    /** @return BelongsTo<WorkflowRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'result' => 'array',
            'status' => RunStepStatus::class,
            'due_at' => 'datetime',
            'executed_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
            'position' => 'integer',
        ];
    }
}
