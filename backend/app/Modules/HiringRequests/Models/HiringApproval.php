<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Models;

use App\Models\User;
use App\Modules\HiringRequests\Enums\ApprovalStatus;
use App\Modules\HiringRequests\Enums\RouteStepKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A step of one request's route (snapshot of the route template at submit) with its decision.
 *
 * @property int $id
 * @property int $hiring_request_id
 * @property int $position
 * @property string $name
 * @property RouteStepKind $kind
 * @property string|null $role
 * @property int|null $approver_id
 * @property int|null $sla_days
 * @property ApprovalStatus $status
 * @property Carbon|null $activated_at
 * @property Carbon|null $due_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $comment
 * @property bool $notified
 * @property bool $escalated
 * @property-read HiringRequest $request
 * @property-read User|null $approver
 * @property-read User|null $decider
 */
final class HiringApproval extends Model
{
    protected $table = 'hiring_request_approvals';

    protected $fillable = [
        'hiring_request_id', 'position', 'name', 'kind', 'role', 'approver_id', 'sla_days', 'status', 'activated_at',
        'due_at', 'decided_by', 'decided_at', 'comment', 'notified', 'escalated',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'waiting', 'notified' => false, 'escalated' => false];

    /** @return BelongsTo<HiringRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(HiringRequest::class, 'hiring_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** SLA breached: still pending after the due time. */
    public function isOverdue(Carbon $now): bool
    {
        return $this->status === ApprovalStatus::Pending && $this->due_at !== null && $now->gt($this->due_at);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => RouteStepKind::class,
            'status' => ApprovalStatus::class,
            'activated_at' => 'datetime',
            'due_at' => 'datetime',
            'decided_at' => 'datetime',
            'notified' => 'boolean',
            'escalated' => 'boolean',
            'position' => 'integer',
        ];
    }
}
