<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of the audit log. Append-only: never updated, removed only by the retention job.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $entity_type
 * @property int $entity_id
 * @property string $action
 * @property array<string, array{from: mixed, to: mixed}>|null $changes
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 * @property-read User|null $user
 */
final class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $fillable = ['user_id', 'entity_type', 'entity_id', 'action', 'changes', 'meta', 'created_at'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'entity_id' => 'integer',
            'changes' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
