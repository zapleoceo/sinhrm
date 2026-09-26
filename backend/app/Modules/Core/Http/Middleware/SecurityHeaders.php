<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every API response (docs/architecture/observability.md, "Заголовки безпеки").
 * The API answers JSON, so its CSP forbids everything; /api/docs (Scramble UI, superadmin) loads its own scripts
 * and styles, so it keeps every header except the CSP.
 */
final class SecurityHeaders
{
    public const string API_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    /** @var array<string, string> */
    public const array HEADERS = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value, false);
        }
        if (! $request->is('api/docs', 'api/docs/*')) {
            $response->headers->set('Content-Security-Policy', self::API_CSP, false);
        }

        return $response;
    }
}
