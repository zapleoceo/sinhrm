<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Models;

use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Scripts\Enums\EvaluationEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $touchpoint_id
 * @property int $script_version_id
 * @property EvaluationEngine $engine
 * @property int $score
 * @property array{steps: list<array<string, mixed>>, next_step: array<string, mixed>, objections: list<array<string, mixed>>, recommendations: list<array<string, mixed>>} $result
 * @property Carbon|null $created_at
 * @property-read ScriptVersion $version
 * @property-read Touchpoint $touchpoint
 */
final class ScriptEvaluation extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['touchpoint_id', 'script_version_id', 'engine', 'score', 'result'];

    /** @return BelongsTo<ScriptVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ScriptVersion::class, 'script_version_id');
    }

    /** @return BelongsTo<Touchpoint, $this> */
    public function touchpoint(): BelongsTo
    {
        return $this->belongsTo(Touchpoint::class);
    }

    public function nextStepFixed(): bool
    {
        return ($this->result['next_step']['fixed'] ?? false) === true;
    }

    /**
     * Short form for timeline items.
     *
     * @return array{id: int, score: int, engine: string, next_step_fixed: bool, script_version_id: int}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'score' => $this->score,
            'engine' => $this->engine->value,
            'next_step_fixed' => $this->nextStepFixed(),
            'script_version_id' => $this->script_version_id,
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['engine' => EvaluationEngine::class, 'score' => 'integer', 'result' => 'array', 'created_at' => 'datetime'];
    }
}
