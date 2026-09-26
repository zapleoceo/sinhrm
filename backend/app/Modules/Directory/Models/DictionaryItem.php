<?php

declare(strict_types=1);

namespace App\Modules\Directory\Models;

use App\Modules\Directory\Enums\DirectoryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Common shape of every company dictionary (branches, cities, departments, positions).
 *
 * @property int $id
 * @property string $name
 * @property DirectoryStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
abstract class DictionaryItem extends Model
{
    protected $fillable = ['name', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => DirectoryStatus::class];
    }
}
