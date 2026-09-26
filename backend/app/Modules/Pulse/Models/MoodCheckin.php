<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One employee's mood for one day (1 = bad … 5 = great). Personal: returned only to the employee themself;
 * everybody else sees aggregates of at least the minimum group.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $day
 * @property int $score
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MoodCheckin extends Model
{
    protected $fillable = ['employee_id', 'day', 'score', 'comment'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['day' => 'date', 'score' => 'integer'];
    }
}
