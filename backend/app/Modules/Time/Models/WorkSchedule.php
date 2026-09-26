<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use App\Modules\Directory\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Default working week of a branch (branch_id null = the company).
 *
 * @property int $id
 * @property int|null $branch_id
 * @property list<int> $days ISO weekdays 1..7
 * @property string $hours_per_day decimal
 * @property-read Branch|null $branch
 */
final class WorkSchedule extends Model
{
    protected $fillable = ['branch_id', 'days', 'hours_per_day'];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['days' => 'array'];
    }
}
