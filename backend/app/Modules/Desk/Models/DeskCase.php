<?php

declare(strict_types=1);

namespace App\Modules\Desk\Models;

use App\Models\User;
use App\Modules\Desk\Enums\CaseStatus;
use App\Modules\People\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A helpdesk case opened by an employee.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $category_id
 * @property string $subject
 * @property string $body
 * @property CaseStatus $status
 * @property int|null $assignee_id
 * @property Carbon|null $first_response_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read DeskCategory $category
 * @property-read User|null $assignee
 * @property-read Collection<int, DeskComment> $comments
 * @property-read Collection<int, DeskAttachment> $attachments
 */
final class DeskCase extends Model
{
    protected $table = 'desk_cases';

    protected $fillable = ['employee_id', 'category_id', 'subject', 'body', 'status', 'assignee_id', 'first_response_at', 'resolved_at', 'closed_at', 'created_by'];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<DeskCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DeskCategory::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<DeskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(DeskComment::class, 'case_id')->orderBy('id');
    }

    /** @return HasMany<DeskAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(DeskAttachment::class, 'case_id')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CaseStatus::class,
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
