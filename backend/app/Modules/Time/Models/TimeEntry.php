<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $timesheet_id
 * @property Carbon $date
 * @property string $hours decimal
 * @property string|null $project
 * @property string|null $category
 * @property string|null $note
 */
final class TimeEntry extends Model
{
    protected $fillable = ['timesheet_id', 'date', 'hours', 'project', 'category', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
