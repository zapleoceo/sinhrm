<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Models;

use App\Models\User;
use App\Modules\HiringRequests\Enums\RouteStepKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A step of the approval route template (settings).
 *
 * @property int $id
 * @property int $position
 * @property string $name
 * @property RouteStepKind $kind
 * @property string|null $role
 * @property int|null $user_id
 * @property int|null $sla_days
 * @property-read User|null $user
 */
final class HiringRouteStep extends Model
{
    protected $fillable = ['position', 'name', 'kind', 'role', 'user_id', 'sla_days'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => RouteStepKind::class, 'position' => 'integer'];
    }
}
