<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

interface ModuleSettingsRepository
{
    /** @return array<string, array{enabled: bool, roles: list<string>}> stored rows by module key */
    public function all(): array;

    /** @param  list<string>  $roles */
    public function save(string $module, bool $enabled, array $roles): void;
}
