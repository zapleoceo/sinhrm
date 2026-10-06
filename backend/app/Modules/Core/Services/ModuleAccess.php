<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\ModuleSettingsRepository;
use App\Modules\Core\Support\ModuleDefinition;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Who may use which module (docs/modules/modules-access.md). This is an EXTRA restriction on top of the
 * module's own gates and policies: it never grants anything. Rules:
 *  - core modules are always on for everyone;
 *  - a switched-off module is closed for everyone, superadmin included (its data stays untouched);
 *  - superadmin sees every switched-on module whatever the role matrix says (cannot lock himself out);
 *  - other users need at least one allowed system role. Contextual roles (hiring manager, interviewer,
 *    line manager) are not system roles: such a person passes with the base role, usually "employee";
 *  - a user without any system role is treated as "employee".
 * Settings are cached for CACHE_SECONDS (dropped on save) and kept in memory for the request (scoped binding).
 */
final class ModuleAccess
{
    public const string CACHE_KEY = 'module_settings';

    public const int CACHE_SECONDS = 60;

    /** @var array<string, array{enabled: bool, roles: list<string>}>|null */
    private ?array $stored = null;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleSettingsRepository $settings,
        private readonly Cache $cache,
    ) {}

    /** @return array{enabled: bool, roles: list<string>} effective setting (stored row, else the module defaults) */
    public function setting(ModuleDefinition $module): array
    {
        if ($module->core) {
            return ['enabled' => true, 'roles' => UserRole::values()];
        }
        /** @var array<string, array{enabled: bool, roles: list<string>}> $stored */
        $stored = $this->stored ?? $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->settings->all());
        $this->stored = $stored;

        return $this->stored[$module->key] ?? ['enabled' => true, 'roles' => $module->defaultRoles];
    }

    /**
     * Reload this instance directly from the repository before a sensitive operation or after a long wait.
     * Normal requests retain their shared cache; callers reuse this exact snapshot for checks and fingerprints.
     *
     * @return array<string, array{enabled: bool, roles: list<string>}>
     */
    public function refreshSettings(): array
    {
        return $this->stored = $this->settings->all();
    }

    public function enabled(string $key): bool
    {
        $module = $this->registry->find($key);

        return $module === null || $this->setting($module)['enabled'];
    }

    public function allows(User $user, string $key): bool
    {
        $module = $this->registry->find($key);
        if ($module === null || $module->core) {
            return true;
        }
        $setting = $this->setting($module);
        if (! $setting['enabled']) {
            return false;
        }
        if ($user->hasRole(UserRole::Superadmin->value)) {
            return true;
        }
        /** @var list<string> $roles */
        $roles = $user->getRoleNames()->all();
        if ($roles === []) {
            $roles = [UserRole::Employee->value];
        }

        return array_intersect($roles, $setting['roles']) !== [];
    }

    /** @return list<string> keys of modules the user may open (GET /api/auth/me → SPA menu and route guard) */
    public function allowedKeys(User $user): array
    {
        $keys = [];
        foreach ($this->registry->all() as $module) {
            if ($this->allows($user, $module->key)) {
                $keys[] = $module->key;
            }
        }

        return $keys;
    }

    /** @param  list<string>  $roles */
    public function save(ModuleDefinition $module, bool $enabled, array $roles): void
    {
        $this->settings->save($module->key, $enabled, $roles);
        $this->cache->forget(self::CACHE_KEY);
        $this->stored = null;
    }
}
