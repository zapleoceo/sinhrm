<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Models;

use App\Modules\TimeOff\Enums\LeaveUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $paid
 * @property LeaveUnit $unit
 * @property string $color
 * @property bool $requires_approval
 * @property bool $tracks_balance
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class LeaveType extends Model
{
    protected $fillable = ['name', 'code', 'paid', 'unit', 'color', 'requires_approval', 'tracks_balance', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = ['paid' => true, 'unit' => 'days', 'requires_approval' => true, 'tracks_balance' => true, 'active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'paid' => 'boolean',
            'unit' => LeaveUnit::class,
            'requires_approval' => 'boolean',
            'tracks_balance' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
