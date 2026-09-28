<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

/**
 * Which API paths the helper may call (the SPA mirrors this list in assistant-tools.ts). Paths are relative to /api
 * ("candidates/12", "timeoff/balances?year=2026"). Closed: operations, the MCP/assistant endpoints themselves
 * (no recursion), session/token management, the anonymous public API and webhooks. Everything else is still subject
 * to the module access check, gates and policies of the user — this list only removes what an AI must never touch.
 */
final class ApiPath
{
    /** @var list<string> */
    public const array FORBIDDEN_PREFIXES = [
        'ops', 'mcp', 'assistant', 'auth/logout', 'sanctum', 'clipper', 'public', 'me/extension-token', 'webhooks',
    ];

    private const string PATTERN = '~^[a-z0-9][a-z0-9\-_/.?=&%:,+ ]*$~i';

    /** Normalized path without the query ("candidates/12"), or null when it is not allowed. */
    public static function normalize(string $path): ?string
    {
        $path = trim($path);
        if (str_starts_with($path, '/api/')) {
            $path = substr($path, 5);
        }
        if ($path === '' || mb_strlen($path) > 300 || preg_match(self::PATTERN, $path) !== 1 || str_contains($path, '..') || str_contains($path, '//')) {
            return null;
        }
        $bare = rtrim(strtolower(strtok($path, '?') ?: ''), '/');
        // Percent-encoding only in the query: an encoded path ("%2e%2e", "%2f") could hide a closed prefix.
        if (str_contains($bare, '%')) {
            return null;
        }
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if ($bare === $prefix || str_starts_with($bare, $prefix.'/')) {
                return null;
            }
        }

        return $bare === '' ? null : $bare;
    }

    /**
     * Query parameters written into the path ("candidates?search=x") merged under the explicit ones.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function query(string $path, array $query): array
    {
        $raw = parse_url('http://x/'.ltrim(trim($path), '/'), PHP_URL_QUERY);
        $inline = [];
        if (is_string($raw)) {
            parse_str($raw, $inline);
        }

        /** @var array<string, mixed> $inline */
        return array_merge($inline, $query);
    }
}
