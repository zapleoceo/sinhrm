<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Models;

use App\Modules\Pulse\Enums\WaveSchedule;
use App\Modules\Pulse\Enums\WaveStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One run of a survey for an audience and a period. The salt is never serialized.
 *
 * @property int $id
 * @property int $survey_id
 * @property int|null $parent_wave_id
 * @property WaveSchedule $schedule
 * @property array{branch_ids?: list<int>, department_ids?: list<int>} $audience
 * @property bool $anonymous
 * @property int $min_group_size
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property WaveStatus $status
 * @property string|null $salt
 * @property int|null $subject_employee_id
 * @property string|null $trigger_key
 * @property array<string, array<string, bool>>|null $segment_visibility
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $responses_count
 * @property-read Survey $survey
 */
final class SurveyWave extends Model
{
    protected $fillable = [
        'survey_id', 'parent_wave_id', 'schedule', 'audience', 'anonymous', 'min_group_size', 'starts_at', 'ends_at',
        'status', 'salt', 'subject_employee_id', 'trigger_key', 'created_by', 'segment_visibility',
    ];

    /** @var list<string> */
    protected $hidden = ['salt', 'segment_visibility'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'scheduled', 'schedule' => 'once', 'anonymous' => true, 'min_group_size' => 5];

    /** @return BelongsTo<Survey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /** @return HasMany<SurveyResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class, 'wave_id');
    }

    public function isLifecycle(): bool
    {
        return $this->subject_employee_id !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'schedule' => WaveSchedule::class,
            'status' => WaveStatus::class,
            'audience' => 'array',
            'segment_visibility' => 'array',
            'anonymous' => 'boolean',
            'min_group_size' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
