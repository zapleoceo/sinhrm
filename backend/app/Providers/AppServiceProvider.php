<?php

declare(strict_types=1);

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scramble's default /docs/api routes are public-by-env; we register our own under /api/docs.
        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every /api route except the docs routes themselves.
        Scramble::routes(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/')
            && ! str_starts_with($route->uri(), 'api/docs'));

        // Group operations by module: App\Modules\<Name>\... → tag "<Name>".
        Scramble::resolveTagsUsing(static function (RouteInfo $routeInfo, Operation $operation): array {
            $class = $routeInfo->className() ?? '';

            return preg_match('/^App\\\\Modules\\\\(\w+)\\\\/', $class, $m) === 1 ? [$m[1]] : ['Other'];
        });

        // Middleware (superadmin only) comes from config/scramble.php.
        Scramble::registerUiRoute('api/docs')->name('api-docs.ui');
        Scramble::registerJsonSpecificationRoute('api/docs.json')->name('api-docs.json');
    }
}
