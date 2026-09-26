<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Reusable 1:1 agenda (e.g. "Career discussion", "Onboarding check-in").
 *
 * @property int $id
 * @property string $name
 * @property list<string> $agenda
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class OneOnOneTemplate extends Model
{
    protected $fillable = ['name', 'agenda', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['agenda' => 'array'];
    }
}
