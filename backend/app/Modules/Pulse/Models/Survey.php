<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Models;

use App\Modules\Pulse\Enums\LifecycleTrigger;
use App\Modules\Pulse\Enums\SurveyType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A survey (questionnaire). It is sent out in waves.
 *
 * @property int $id
 * @property string $title
 * @property SurveyType $type
 * @property string|null $description
 * @property list<array{id: string, type: string, text: string, options?: list<string>, required?: bool}> $questions
 * @property LifecycleTrigger|null $lifecycle_trigger
 * @property bool $active
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $waves_count
 * @property-read Collection<int, SurveyWave> $waves
 */
final class Survey extends Model
{
    protected $fillable = ['title', 'type', 'description', 'questions', 'lifecycle_trigger', 'active', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    /**
     * A lifecycle survey (hire_30 / hire_90 / exit): its waves are personal — one known subject, read by HR only,
     * never broken down and never the other side of a comparison. It takes no manual (team) waves.
     */
    public function isLifecycle(): bool
    {
        return $this->type === SurveyType::Lifecycle || $this->lifecycle_trigger !== null;
    }

    /** @return HasMany<SurveyWave, $this> */
    public function waves(): HasMany
    {
        return $this->hasMany(SurveyWave::class)->orderByDesc('starts_at')->orderByDesc('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SurveyType::class,
            'questions' => 'array',
            'lifecycle_trigger' => LifecycleTrigger::class,
            'active' => 'boolean',
        ];
    }
}
