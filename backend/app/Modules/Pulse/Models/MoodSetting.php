<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Mood check-in settings (a single row): when the question is shown, its text, whether it is required, the alert
 * threshold for managers and the minimum group for team aggregates.
 *
 * @property int $id
 * @property list<int> $weekdays
 * @property string $question
 * @property bool $required
 * @property string $alert_drop
 * @property int $min_group
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MoodSetting extends Model
{
    public const array DEFAULTS = [
        'weekdays' => [1, 2, 3, 4, 5],
        'question' => 'Як ваш настрій сьогодні?',
        'required' => false,
        'alert_drop' => '0.50',
        'min_group' => 5,
    ];

    protected $fillable = ['weekdays', 'question', 'required', 'alert_drop', 'min_group'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['weekdays' => 'array', 'required' => 'boolean', 'min_group' => 'integer'];
    }
}
