<?php

declare(strict_types=1);

namespace App\Modules\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $kind builder | catalog
 * @property array<string, mixed> $definition
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SavedReport extends Model
{
    public const string BUILDER = 'builder';

    public const string CATALOG = 'catalog';

    protected $table = 'saved_reports';

    protected $fillable = ['user_id', 'name', 'kind', 'definition'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array'];
    }
}
