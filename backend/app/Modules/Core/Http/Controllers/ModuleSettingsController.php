<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Http\Requests\UpdateModuleSettingRequest;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\ModuleDefinition;
use Illuminate\Http\JsonResponse;

/**
 * "Модулі" admin page (superadmin, gate manage-modules): switch modules on/off company-wide and choose which
 * system roles see them. Switching off never deletes data. Core modules are read-only here (422 module_core).
 */
final class ModuleSettingsController
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleAccess $access,
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => array_values(array_map($this->present(...), $this->modules->all()))]);
    }

    public function update(UpdateModuleSettingRequest $request, string $key): JsonResponse
    {
        $module = $this->modules->find($key);
        if ($module === null) {
            return new JsonResponse(['message' => 'Not Found'], 404);
        }
        if ($module->core) {
            return new JsonResponse(['message' => 'module_core', 'errors' => ['enabled' => ['module_core']]], 422);
        }
        $this->access->save($module, $request->enabled(), $request->roles());

        return new JsonResponse(['data' => $this->present($module)]);
    }

    /** @return array<string, mixed> */
    private function present(ModuleDefinition $module): array
    {
        $setting = $this->access->setting($module);

        return [
            'key' => $module->key,
            'name_key' => $module->nameKey(),
            'icon' => $module->icon,
            'group' => $module->group,
            'core' => $module->core,
            'enabled' => $setting['enabled'],
            'roles' => $setting['roles'],
            'default_roles' => $module->defaultRoles,
        ];
    }
}
