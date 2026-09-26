<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Models;

use App\Modules\Workflows\Enums\WorkflowKind;
use App\Modules\Workflows\Enums\WorkflowTrigger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property WorkflowKind $kind
 * @property WorkflowTrigger $trigger
 * @property bool $active
 * @property int $probation_days
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $runs_count
 * @property-read Collection<int, WorkflowStep> $steps
 */
final class WorkflowTemplate extends Model
{
    protected $fillable = ['name', 'kind', 'trigger', 'active', 'probation_days', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['trigger' => 'manual', 'active' => true, 'probation_days' => 90];

    /** @return HasMany<WorkflowStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'template_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<WorkflowRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class, 'template_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => WorkflowKind::class,
            'trigger' => WorkflowTrigger::class,
            'active' => 'boolean',
            'probation_days' => 'integer',
        ];
    }
}
