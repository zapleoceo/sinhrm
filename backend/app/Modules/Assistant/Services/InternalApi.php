<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Models\User;
use App\Modules\Assistant\Support\ApiPath;
use App\Modules\Assistant\Support\AssistantDataPolicy;
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
 * SPA, so the helper can never do more than the user can. GETs also require AssistantDataPolicy; responses are
 * projected before MAX_CHARS. Writes expose only status/fixed error codes. The container's current request is restored afterwards.
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
        if ($clean === null || ! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ($method === 'GET' && AssistantDataPolicy::readPath($path) === null)) {
            return ['status' => 403, 'error' => 'forbidden_path'];
        }
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        $sub = $method === 'GET'
            ? Request::create('/api/'.$clean, 'GET', ApiPath::query($path, $payload), [], [], $server)
            : Request::create('/api/'.$clean, $method, [], [], [], $server, (string) json_encode((object) $payload));

        return self::summarize($this->dispatch($user, $sub), $method, $clean);
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
    private static function summarize(Response $response, string $method, string $path): array
    {
        $status = $response->getStatusCode();
        if ($status >= 400) {
            return ['status' => $status, 'error' => match ($status) {
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                422 => 'validation_failed',
                429 => 'rate_limited',
                default => 'api_error',
            }];
        }
        if ($method !== 'GET' || $status === 204) {
            return ['status' => $status];
        }
        if (! $response instanceof JsonResponse) {
            return ['status' => $status, 'error' => 'non_json_response'];
        }
        // Project before encoding or truncating: previews must never contain discarded source fields.
        $data = AssistantDataPolicy::project($path, $response->getData(true));
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
        if (mb_strlen($json) > self::MAX_CHARS) {
            return ['status' => $status, 'truncated' => true, 'data_preview' => mb_substr($json, 0, self::MAX_CHARS)];
        }

        return ['status' => $status, 'data' => $data];
    }
}
