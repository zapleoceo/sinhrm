<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Http\Middleware\EnsureModuleAccessible;
use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Base provider for every domain module (app/Modules/<Name>).
 *
 * A module keeps its own routes and migrations (Database/Migrations),
 * so adding a module never requires touching global files except bootstrap/providers.php.
 *
 * Routes, both under /api/<prefix>:
 *  - routes.php      → 'api' middleware group (JSON API; Sanctum makes SPA requests stateful);
 *  - routes.web.php  → 'web' middleware group (always has a session + cookies), for browser
 *                      redirects that arrive from third parties, e.g. the OAuth callback.
 *
 * Module access (docs/modules/modules-access.md): every module registers its metadata in ModuleRegistry, and
 * the routes of a non-core module also get EnsureModuleAccessible (switched off / role not allowed → 403).
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** URL prefix under /api, e.g. "users" → /api/users. Empty = no prefix. */
    protected string $prefix = '';

    /** Module key; empty = kebab-case of the module folder (HiringRequests → "hiring-requests"). */
    protected string $moduleKey = '';

    /** Material icon on the "Модулі" admin page. */
    protected string $moduleIcon = 'extension';

    /** Sidebar group of the module's pages: recruiting, people, perform, services, admin. */
    protected string $moduleGroup = 'services';

    /** Core modules (Core, Auth, Users, Integrations, Directory, Overview) cannot be switched off or restricted. */
    protected bool $coreModule = false;

    /**
     * System roles that open the module out of the box — exactly who can use it today through its gates.
     * null = every role. Superadmin is always allowed for an enabled module.
     *
     * @var list<UserRole>|null
     */
    protected ?array $defaultRoles = null;

    public function boot(): void
    {
        $dir = $this->moduleDir();

        $this->app->make(ModuleRegistry::class)->register(new ModuleDefinition(
            key: $this->moduleKey(),
            namespace: 'App\\Modules\\'.basename($dir).'\\',
            icon: $this->moduleIcon,
            group: $this->coreModule ? 'core' : $this->moduleGroup,
            core: $this->coreModule,
            defaultRoles: UserRole::valuesOf($this->defaultRoles ?? UserRole::cases()),
        ));

        if (is_dir($dir.'/Database/Migrations')) {
            $this->loadMigrationsFrom($dir.'/Database/Migrations');
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        foreach (['routes.php' => 'api', 'routes.web.php' => 'web'] as $file => $group) {
            if (is_file($dir.'/'.$file)) {
                Route::middleware($this->routeMiddleware($group))
                    ->prefix(trim('api/'.$this->prefix, '/'))
                    ->group($dir.'/'.$file);
            }
        }
    }

    public function moduleKey(): string
    {
        return $this->moduleKey !== '' ? $this->moduleKey : Str::kebab(basename($this->moduleDir()));
    }

    /**
     * Middleware of the module's route groups: the base group plus the access check for non-core modules.
     * Modules that load extra route files themselves (SafeSpeak public routes) use it too.
     *
     * @return list<string>
     */
    protected function routeMiddleware(string $group): array
    {
        return [$group, ...$this->accessMiddleware()];
    }

    /** @return list<string> the module access check (none for core modules) */
    protected function accessMiddleware(): array
    {
        return $this->coreModule ? [] : [EnsureModuleAccessible::class.':'.$this->moduleKey()];
    }

    protected function moduleDir(): string
    {
        $file = (new ReflectionClass(static::class))->getFileName();

        return dirname((string) $file, 2);
    }
}
