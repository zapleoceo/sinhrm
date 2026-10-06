<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Models\User;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The whole SinHRM API as the helper sees it, built from the live route table (never a hand-kept list, so a new
 * endpoint of any module is available at once): authenticated /api routes of modules the user may open, minus
 * ApiPath's closed prefixes. Each entry: method, path, module, the first docblock line of the controller action and
 * the validation rules of its FormRequest (body fields; query fields for GET). Access is still checked per call by the
 * real endpoint — the catalog only helps the model find the right one.
 */
final class EndpointCatalog
{
    private const int MAX_RESULTS = 8;

    private const int MAX_SUMMARY = 120;

    private const int MAX_RULE_LENGTH = 80;

    private const int CACHE_SECONDS = 86400;

    /** @var list<array{method: string, path: string, module: string, summary: string, fields: array<string, string>, controller: string}>|null */
    private ?array $all = null;

    public function __construct(
        private readonly Router $router,
        private readonly ModuleRegistry $modules,
        private readonly ModuleAccess $access,
        private readonly Cache $cache,
    ) {}

    /**
     * Best matches for the query (English keywords), optionally within one module; with neither → the module list.
     *
     * @return array<string, mixed>
     */
    public function search(User $user, string $query, ?string $module = null): array
    {
        $allowed = array_values(array_filter($this->all(), fn (array $e): bool => $this->access->allows($user, $e['module'])));
        $module = $module === null || trim($module) === '' ? null : strtolower(trim($module));
        $tokens = self::tokens($query);

        if ($tokens === [] && $module === null) {
            $counts = [];
            foreach ($allowed as $e) {
                $counts[$e['module']] = ($counts[$e['module']] ?? 0) + 1;
            }
            ksort($counts);

            return ['modules' => $counts, 'hint' => 'Call find_endpoints again with a query and/or module.'];
        }

        $scored = [];
        foreach ($allowed as $e) {
            if ($module !== null && $e['module'] !== $module) {
                continue;
            }
            $score = $tokens === [] ? 1 : self::score($e, $tokens);
            if ($score > 0) {
                $scored[] = [$score, $e];
            }
        }
        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        // Compact on purpose: free-lane models choke on long tool results (prod 28.09: 20 endpoints with full rules
        // ≈ 8 000 chars per call made turns stall). Field names only; the endpoint validates the values anyway.
        return [
            'endpoints' => array_map(static fn (array $pair): array => array_filter([
                'method' => $pair[1]['method'],
                'path' => $pair[1]['path'],
                'summary' => mb_substr($pair[1]['summary'], 0, self::MAX_SUMMARY),
                'fields' => array_keys($pair[1]['fields']),
            ], static fn (mixed $v): bool => $v !== '' && $v !== []), array_slice($scored, 0, self::MAX_RESULTS)),
            'total_matches' => count($scored),
            'hint' => 'Lists are paginated: for a count call api_get with perPage=1 and read meta.total.',
        ];
    }

    /** Route metadata, never arbitrary client path text, for sanitized model call arguments. */
    public function promptPath(User $user, string $method, string $path): ?string
    {
        $clean = ApiPath::normalize($path);
        if ($clean === null) {
            return null;
        }
        foreach ($this->all() as $entry) {
            if ($entry['method'] !== $method || ! $this->access->allows($user, $entry['module'])) {
                continue;
            }
            $parts = preg_split('~(\{[^}]+\})~', $entry['path'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $pattern = implode('', array_map(static fn (string $part): string => str_starts_with($part, '{') ? '[1-9][0-9]*' : preg_quote($part, '~'), $parts));
            if (preg_match('~^'.$pattern.'$~D', $clean) === 1) {
                return $clean;
            }
        }

        return null;
    }

    /** @return list<array{method: string, path: string, module: string, summary: string, fields: array<string, string>, controller: string}> */
    private function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }
        $routes = $this->router->getRoutes()->getRoutes();
        // Reflection over every action is the expensive part: cache it per route-table fingerprint (a deploy with
        // other routes gets a new key; stale keys simply expire).
        $fingerprint = md5(implode("\n", array_map(static fn (Route $r): string => implode('|', $r->methods()).' '.$r->uri().' '.$r->getActionName(), $routes)));

        /** @var list<array{method: string, path: string, module: string, summary: string, fields: array<string, string>, controller: string}> $entries */
        $entries = $this->cache->remember('assistant.catalog.'.$fingerprint, self::CACHE_SECONDS, function () use ($routes): array {
            $entries = [];
            foreach ($routes as $route) {
                $entry = $this->describe($route);
                if ($entry !== null) {
                    array_push($entries, ...$entry);
                }
            }

            return $entries;
        });

        return $this->all = $entries;
    }

    /** @return list<array{method: string, path: string, module: string, summary: string, fields: array<string, string>, controller: string}>|null */
    private function describe(Route $route): ?array
    {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/') || ApiPath::normalize(preg_replace('~\{[^}]+\}~', '1', substr($uri, 4)) ?? '') === null) {
            return null;
        }
        $authenticated = false;
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'auth:sanctum')) {
                $authenticated = true;
            }
        }
        $controller = $route->getActionName();
        $module = $this->modules->forClass($controller);
        if (! $authenticated || $module === null) {
            return null;
        }
        [$summary, $fields] = $this->reflect($controller);

        $entries = [];
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $entries[] = [
                'method' => $method,
                'path' => substr($uri, 4),
                'module' => $module->key,
                'summary' => $summary,
                'fields' => $fields,
                'controller' => $controller,
            ];
        }

        return $entries;
    }

    /** @return array{0: string, 1: array<string, string>} docblock summary and FormRequest rules of the action */
    private function reflect(string $action): array
    {
        [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return ['', []];
        }
        $summary = self::summary((string) $reflection->getDocComment());
        $fields = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                $fields = $this->rules($type->getName());
            }
        }

        return [$summary, $fields];
    }

    /**
     * Rules of a FormRequest without a real request; a rules() that needs one (route model, user) is skipped.
     *
     * @param  class-string<FormRequest>  $class
     * @return array<string, string>
     */
    private function rules(string $class): array
    {
        $request = new $class;
        if (! method_exists($request, 'rules')) {
            return [];
        }
        try {
            $rules = $request->rules();
        } catch (Throwable) {
            return [];
        }
        if (! is_array($rules)) {
            return [];
        }
        $out = [];
        foreach ($rules as $field => $rule) {
            $parts = is_array($rule) ? $rule : explode('|', (string) $rule);
            $text = implode('|', array_map(static fn (mixed $p): string => is_string($p) ? $p : (is_object($p) ? class_basename($p) : ''), $parts));
            $out[(string) $field] = mb_substr($text, 0, self::MAX_RULE_LENGTH);
        }

        return $out;
    }

    private static function summary(string $doc): string
    {
        foreach (preg_split('~\R~', $doc) ?: [] as $line) {
            $line = trim(preg_replace('~^\s*(/\*\*|\*/|\*)~', '', $line) ?? '');
            if ($line !== '' && ! str_starts_with($line, '@')) {
                return mb_substr($line, 0, 200);
            }
        }

        return '';
    }

    /** @return list<string> */
    private static function tokens(string $query): array
    {
        $words = preg_split('~[^\pL\pN]+~u', mb_strtolower($query)) ?: [];

        return array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 2));
    }

    /**
     * @param  array{method: string, path: string, module: string, summary: string, fields: array<string, string>, controller: string}  $e
     * @param  list<string>  $tokens
     */
    private static function score(array $e, array $tokens): int
    {
        $path = strtolower($e['path'].' '.$e['module']);
        $text = mb_strtolower($e['summary'].' '.class_basename(strtok($e['controller'], '@') ?: '').' '.implode(' ', array_keys($e['fields'])));
        $score = 0;
        foreach ($tokens as $token) {
            $stem = mb_strlen($token) > 4 ? mb_substr($token, 0, -1) : $token; // candidates ~ candidate
            if (str_contains($path, $stem)) {
                $score += 3;
            } elseif (str_contains($text, $stem)) {
                $score += 1;
            }
        }

        return $score;
    }
}
