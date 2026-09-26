<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A competency rated in review cycles on its scale.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $scale_id
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RatingScale $scale
 */
final class Competency extends Model
{
    protected $fillable = ['name', 'description', 'scale_id', 'active'];

    /** @return BelongsTo<RatingScale, $this> */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(RatingScale::class, 'scale_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
