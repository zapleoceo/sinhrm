<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Core\Http\Middleware\EnsureModuleAccessible;
use App\Modules\Core\Http\Middleware\RequireOpsSecret;
use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Structural guard over the whole route table (route:list), so a new route cannot silently skip a layer of the
 * access model: every /api route of a module is behind auth:sanctum + EnsureUserIsActive unless it is one of the
 * known anonymous routes below; every route of a non-core module carries the module access check (ADR 0009,
 * docs/modules/modules-access.md); anonymous Safe Speak / career routes never start a session (anonymity).
 */
final class RouteGuardsTest extends TestCase
{
    /** Anonymous by design; each one is guarded by its own mechanism (named in the comment). */
    private const array ANONYMOUS = [
        'core.health',                       // public health probe, no data
        'core.ops.migrate',                  // RequireOpsSecret
        'core.ops.jobs',                     // RequireOpsSecret
        'core.ops.demo_fill',                // RequireOpsSecret
        'auth.google.redirect',              // OAuth start (web group, state)
        'auth.google.callback',              // OAuth callback (state check)
        'channels.webhook',                  // per-provider signature / secret, throttled
        'channels.webhook.handshake',        // per-provider verify token, throttled
        'recruiting.public.vacancies',       // career page
        'recruiting.public.vacancies.show',  // career page
        'recruiting.public.vacancies.apply', // career page, throttled + per-IP-hash limit
        'safe-speak.public.submit',          // anonymous report, limits in the service
        'safe-speak.public.follow-up',       // access code
        'safe-speak.public.reply',           // access code
    ];

    /** Anonymous routes that must stay outside any session (no IP / user agent in the sessions table). */
    private const array SESSIONLESS = [
        'recruiting.public.vacancies',
        'recruiting.public.vacancies.show',
        'recruiting.public.vacancies.apply',
        'safe-speak.public.submit',
        'safe-speak.public.follow-up',
        'safe-speak.public.reply',
    ];

    public function test_the_anonymous_allowlist_matches_existing_routes(): void
    {
        foreach (self::ANONYMOUS as $name) {
            $this->assertTrue(RouteFacade::has($name), "allowlisted route {$name} no longer exists — update the list");
        }
    }

    public function test_every_module_route_requires_an_active_login_unless_allowlisted(): void
    {
        $checked = 0;
        foreach ($this->moduleRoutes() as $route) {
            if (in_array($route->getName(), self::ANONYMOUS, true)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $label = implode('|', $route->methods()).' '.$route->uri();
            $this->assertContains('auth:sanctum', $middleware, "{$label} is reachable without a login");
            $this->assertContains(EnsureUserIsActive::class, $middleware, "{$label} lets a blocked login in");
            $checked++;
        }
        $this->assertGreaterThan(300, $checked, 'the route table was not loaded');
    }

    public function test_ops_routes_require_the_ops_secret(): void
    {
        foreach (['core.ops.migrate', 'core.ops.jobs', 'core.ops.demo_fill'] as $name) {
            $this->assertContains(RequireOpsSecret::class, $this->named($name)->gatherMiddleware(), $name);
        }
    }

    public function test_every_route_of_a_non_core_module_checks_module_access(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $modules = [];
        foreach ($this->moduleRoutes() as $route) {
            $module = $registry->forClass($route->getControllerClass() ?? '');
            if ($module === null) {
                $this->fail($route->uri().' is not owned by a registered module');
            }
            if ($module->core) {
                continue;
            }
            $this->assertContains(
                EnsureModuleAccessible::class.':'.$module->key,
                $route->gatherMiddleware(),
                implode('|', $route->methods()).' '.$route->uri().' bypasses the module on/off and role switch',
            );
            $modules[$module->key] = true;
        }
        $this->assertCount(22, $modules, 'every non-core module has routes under the check');
    }

    public function test_anonymous_public_routes_never_start_a_session(): void
    {
        foreach (self::SESSIONLESS as $name) {
            $middleware = $this->named($name)->gatherMiddleware();
            foreach (['web', 'api', 'auth:sanctum', StartSession::class] as $forbidden) {
                $this->assertNotContains($forbidden, $middleware, "{$name} must stay sessionless and anonymous");
            }
        }
    }

    private function named(string $name): Route
    {
        return RouteFacade::getRoutes()->getByName($name) ?? $this->fail("route {$name} is missing");
    }

    /** @return list<Route> routes under /api whose controller lives in app/Modules */
    private function moduleRoutes(): array
    {
        $routes = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/') && str_starts_with($route->getControllerClass() ?? '', 'App\\Modules\\')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }
}
