<?php

declare(strict_types=1);

namespace App\Modules\Core\Repositories;

use App\Modules\Core\Contracts\ModuleSettingsRepository;
use App\Modules\Core\Models\ModuleSetting;

final class EloquentModuleSettingsRepository implements ModuleSettingsRepository
{
    public function all(): array
    {
        $rows = [];
        foreach (ModuleSetting::query()->get() as $row) {
            $rows[$row->module] = ['enabled' => $row->enabled, 'roles' => $row->roles];
        }

        return $rows;
    }

    public function save(string $module, bool $enabled, array $roles): void
    {
        ModuleSetting::query()->updateOrCreate(['module' => $module], ['enabled' => $enabled, 'roles' => $roles]);
    }
}
