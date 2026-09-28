<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Models\User;
use App\Modules\Assistant\Support\ApiPath;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Calls the real SinHRM API in-process as the given user (MCP tool calls): the sub-request goes through the router
 * with all route middleware — module access, gates, policies, validation, throttles — exactly like a request from the
 * SPA, so the helper can never do more than the user can. Only ApiPath-allowed paths; the answer is cut to MAX_CHARS
 * for the model. The container's current request is restored afterwards.
 */
final readonly class InternalApi
{
    public const int MAX_CHARS = 12000;

    public function __construct(
        private Application $app,
        private Router $router,
        private Auth $auth,
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  query parameters (GET) or JSON body
     * @return array<string, mixed> {status, data} | {status, error} | {status, truncated, data_preview}
     */
    public function call(User $user, string $method, string $path, array $payload = []): array
    {
        $method = strtoupper($method);
        $clean = ApiPath::normalize($path);
        if ($clean === null || ! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ['status' => 403, 'error' => 'forbidden_path'];
        }
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        $sub = $method === 'GET'
            ? Request::create('/api/'.$clean, 'GET', ApiPath::query($path, $payload), [], [], $server)
            : Request::create('/api/'.$clean, $method, [], [], [], $server, (string) json_encode((object) $payload));

        return self::summarize($this->dispatch($user, $sub));
    }

    private function dispatch(User $user, Request $sub): Response
    {
        $original = $this->app->make('request');
        $this->app->instance('request', $sub);
        Facade::clearResolvedInstance('request');
        // Same user for the sub-request's auth:sanctum (no second token lookup, no cookie session needed). Only ever
        // the user who authenticated the outer request: this class must never be used to act as someone else.
        $this->auth->guard('sanctum')->setUser($user);
        try {
            return $this->router->dispatch($sub);
        } catch (Throwable $e) {
            // Thrown before the route pipeline (404/405); everything inside it is rendered by the router already.
            return $this->exceptions->render($sub, $e);
        } finally {
            $this->app->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }
    }

    /** @return array<string, mixed> */
    private static function summarize(Response $response): array
    {
        $status = $response->getStatusCode();
        if (! $response instanceof JsonResponse) {
            $type = (string) $response->headers->get('Content-Type', '');

            return ['status' => $status, 'content_type' => $type, 'note' => $status === 204 ? 'no content' : 'non-JSON response omitted'];
        }
        $data = $response->getData(true);
        if ($status >= 400) {
            $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? null) : null;

            return array_filter([
                'status' => $status,
                'error' => is_string($message) ? $message : 'error',
                'errors' => is_array($data) ? ($data['errors'] ?? null) : null,
            ], static fn (mixed $v): bool => $v !== null);
        }
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
        if (mb_strlen($json) > self::MAX_CHARS) {
            return ['status' => $status, 'truncated' => true, 'data_preview' => mb_substr($json, 0, self::MAX_CHARS)];
        }

        return ['status' => $status, 'data' => $data];
    }
}
