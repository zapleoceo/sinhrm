<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\StageKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $pipeline_id
 * @property string $name
 * @property StageKind $kind
 * @property int $position
 * @property bool $is_terminal
 * @property-read Pipeline $pipeline
 */
final class PipelineStage extends Model
{
    protected $fillable = ['pipeline_id', 'name', 'kind', 'position', 'is_terminal'];

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /** Terminal "closed" stage: moving here rejects the application and needs a reason. */
    public function isReject(): bool
    {
        return $this->is_terminal && $this->kind === StageKind::Closed;
    }

    /** Terminal "hire" stage: moving here marks the application as hired. */
    public function isHire(): bool
    {
        return $this->is_terminal && $this->kind === StageKind::Hire;
    }

    /** Application status an application gets on this stage. */
    public function applicationStatus(): ApplicationStatus
    {
        return match (true) {
            $this->isReject() => ApplicationStatus::Rejected,
            $this->isHire() => ApplicationStatus::Hired,
            default => ApplicationStatus::Active,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => StageKind::class, 'is_terminal' => 'boolean', 'position' => 'integer'];
    }
}
