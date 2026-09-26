<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use App\Models\User;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A candidate on a vacancy: current stage, outcome, last real contact.
 *
 * @property int $id
 * @property int $candidate_id
 * @property int $vacancy_id
 * @property int $stage_id
 * @property ApplicationStatus $status
 * @property int|null $reject_reason_id
 * @property string|null $rejected_note
 * @property Carbon|null $stage_entered_at
 * @property Carbon|null $last_touch_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Candidate $candidate
 * @property-read Vacancy $vacancy
 * @property-read PipelineStage $stage
 * @property-read RejectReason|null $rejectReason
 * @property-read Collection<int, StageChange> $stageChanges
 * @property-read Collection<int, User> $interviewers
 */
final class Application extends Model
{
    protected $fillable = [
        'candidate_id', 'vacancy_id', 'stage_id', 'status', 'reject_reason_id', 'rejected_note',
        'stage_entered_at', 'last_touch_at', 'closed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /** @return BelongsTo<RejectReason, $this> */
    public function rejectReason(): BelongsTo
    {
        return $this->belongsTo(RejectReason::class);
    }

    /** @return HasMany<StageChange, $this> */
    public function stageChanges(): HasMany
    {
        return $this->hasMany(StageChange::class)->orderBy('at')->orderBy('id');
    }

    /**
     * Contextual role: users who interview this candidate for this vacancy (they see only this application).
     *
     * @return BelongsToMany<User, $this>
     */
    public function interviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'application_interviewers')->withPivot('created_at')->orderBy('users.id');
    }

    /** Last contact, or the moment the application appeared when nobody touched it yet. */
    public function lastActivityAt(): ?Carbon
    {
        return $this->last_touch_at ?? $this->created_at;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'stage_entered_at' => 'datetime',
            'last_touch_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
