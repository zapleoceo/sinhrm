<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Models;

use App\Modules\Directory\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $date
 * @property string $name
 * @property int|null $branch_id null = every branch
 * @property-read Branch|null $branch
 */
final class Holiday extends Model
{
    protected $fillable = ['date', 'name', 'branch_id'];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
