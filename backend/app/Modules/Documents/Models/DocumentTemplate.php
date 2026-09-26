<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $category
 * @property string $body
 * @property bool $archived
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DocumentTemplate extends Model
{
    protected $fillable = ['name', 'category', 'body', 'archived', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['archived' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived' => 'boolean'];
    }
}
