<?php

declare(strict_types=1);

namespace App\Modules\Desk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A case category with its SLA targets (hours from opening; null = no target) and default assignee.
 *
 * @property int $id
 * @property string $name
 * @property int|null $first_response_hours
 * @property int|null $resolve_hours
 * @property int|null $default_assignee_id
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DeskCategory extends Model
{
    protected $table = 'desk_categories';

    protected $fillable = ['name', 'first_response_hours', 'resolve_hours', 'default_assignee_id', 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['first_response_hours' => 'integer', 'resolve_hours' => 'integer', 'default_assignee_id' => 'integer', 'active' => 'boolean'];
    }
}
