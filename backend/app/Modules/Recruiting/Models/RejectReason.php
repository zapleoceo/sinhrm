<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rejection reason (manageable dictionary; deactivated, never deleted).
 *
 * @property int $id
 * @property string $name
 * @property bool $active
 */
final class RejectReason extends Model
{
    protected $fillable = ['name', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
