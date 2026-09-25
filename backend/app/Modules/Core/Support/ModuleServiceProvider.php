<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
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
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** URL prefix under /api, e.g. "users" → /api/users. Empty = no prefix. */
    protected string $prefix = '';

    public function boot(): void
    {
        $dir = $this->moduleDir();

        if (is_dir($dir.'/Database/Migrations')) {
            $this->loadMigrationsFrom($dir.'/Database/Migrations');
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        foreach (['routes.php' => 'api', 'routes.web.php' => 'web'] as $file => $group) {
            if (is_file($dir.'/'.$file)) {
                Route::middleware($group)
                    ->prefix(trim('api/'.$this->prefix, '/'))
                    ->group($dir.'/'.$file);
            }
        }
    }

    protected function moduleDir(): string
    {
        $file = (new ReflectionClass(static::class))->getFileName();

        return dirname((string) $file, 2);
    }
}
