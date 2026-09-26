<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Support\ModuleDefinition;

/** Every module that booted (filled by ModuleServiceProvider::boot, in bootstrap/providers.php order). */
final class ModuleRegistry
{
    /** @var array<string, ModuleDefinition> */
    private array $modules = [];

    public function register(ModuleDefinition $module): void
    {
        $this->modules[$module->key] = $module;
    }

    /** @return array<string, ModuleDefinition> */
    public function all(): array
    {
        return $this->modules;
    }

    public function find(string $key): ?ModuleDefinition
    {
        return $this->modules[$key] ?? null;
    }

    /** Module that owns a class (by namespace), e.g. a ScheduledJob; null when the class is outside app/Modules. */
    public function forClass(string $class): ?ModuleDefinition
    {
        foreach ($this->modules as $module) {
            if (str_starts_with($class, $module->namespace)) {
                return $module;
            }
        }

        return null;
    }
}
