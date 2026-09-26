<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AI screening of one application (tz6). Advisory: shown as "Оцінка ШІ, рішення за людиною".
 *
 * @property int $id
 * @property int $application_id
 * @property int $candidate_id
 * @property int $vacancy_id
 * @property string $status pending|done|failed
 * @property string $trigger manual|auto
 * @property int|null $score 0..100
 * @property string|null $verdict fit|maybe|no
 * @property string|null $summary
 * @property list<string>|null $strengths
 * @property list<string>|null $gaps
 * @property list<string>|null $questions
 * @property string $prompt_version
 * @property int|null $ai_request_id
 * @property string|null $error
 * @property int|null $requested_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Vacancy $vacancy
 * @property-read User|null $requester
 */
final class CandidateScreening extends Model
{
    public const string PENDING = 'pending';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    protected $fillable = [
        'application_id', 'candidate_id', 'vacancy_id', 'status', 'trigger', 'score', 'verdict', 'summary', 'strengths',
        'gaps', 'questions', 'prompt_version', 'ai_request_id', 'error', 'requested_by', 'completed_at',
    ];

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'strengths' => 'array',
            'gaps' => 'array',
            'questions' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
