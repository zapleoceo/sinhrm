<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Company-wide switch of one module: on/off + which system roles see it. Never deletes module data.
 *
 * @property string $module
 * @property bool $enabled
 * @property list<string> $roles
 */
final class ModuleSetting extends Model
{
    protected $table = 'module_settings';

    protected $fillable = ['module', 'enabled', 'roles'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'roles' => 'array'];
    }
}
