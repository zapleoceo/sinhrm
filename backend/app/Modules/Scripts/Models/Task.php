<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Models;

use App\Models\User;
use App\Modules\People\Models\Employee;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Scripts\Enums\TaskType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A to-do in the unified task list: recruiter follow-ups (script rules, mail agent, manual), workflow steps and
 * document acknowledgements (employee_id + link).
 *
 * @property int $id
 * @property int $assignee_id
 * @property int|null $candidate_id
 * @property int|null $application_id
 * @property int|null $employee_id
 * @property TaskType $type
 * @property string $title
 * @property string|null $link
 * @property Carbon $due_at
 * @property Carbon|null $done_at
 * @property string|null $template_key
 * @property string|null $rule_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $assignee
 * @property-read Candidate|null $candidate
 * @property-read Application|null $application
 * @property-read Employee|null $employee
 */
final class Task extends Model
{
    protected $fillable = [
        'assignee_id', 'candidate_id', 'application_id', 'employee_id', 'type', 'title', 'link', 'due_at', 'done_at',
        'template_key', 'rule_key',
    ];

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => TaskType::class, 'due_at' => 'datetime', 'done_at' => 'datetime'];
    }
}
