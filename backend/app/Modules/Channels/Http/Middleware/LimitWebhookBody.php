<?php

declare(strict_types=1);

namespace App\Modules\Channels\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Webhook bodies above 1 MB are refused (413) before they are parsed or hashed. */
final class LimitWebhookBody
{
    public const int MAX_BYTES = 1_048_576;

    public function handle(Request $request, Closure $next): Response
    {
        $declared = (int) $request->header('Content-Length', '0');
        if ($declared > self::MAX_BYTES || strlen($request->getContent()) > self::MAX_BYTES) {
            throw new HttpException(413, 'Payload too large.');
        }

        return $next($request);
    }
}
