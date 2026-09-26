<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A rating (and optional comment) of one competency in one assignment.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $competency_id
 * @property int $rating
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ReviewAnswer extends Model
{
    protected $fillable = ['assignment_id', 'competency_id', 'rating', 'comment'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }
}
