<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for every domain module (app/Modules/<Name>).
 *
 * A module keeps its own routes (routes.php) and migrations (Database/Migrations),
 * so adding a module never requires touching global files except bootstrap/providers.php.
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

        if (is_file($dir.'/routes.php') && ! $this->app->routesAreCached()) {
            Route::middleware('api')
                ->prefix(trim('api/'.$this->prefix, '/'))
                ->group($dir.'/routes.php');
        }
    }

    protected function moduleDir(): string
    {
        $file = (new ReflectionClass(static::class))->getFileName();

        return dirname((string) $file, 2);
    }
}
