<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Neon routes connections by SNI. The libpq bundled with the vercel-php runtime is too old for SNI,
 * so Neon rejects it with "Endpoint ID is not specified". Neon's documented workaround: pass the
 * endpoint id inside the password as "endpoint=<id>;<password>" (https://neon.tech/sni).
 *
 * The workaround is applied ONLY when libpq lacks SNI (< 14): a modern client (CI, developer machine)
 * sends SNI and Neon then treats the whole prefixed string as the password, failing authentication.
 * Non-Neon URLs are returned unchanged.
 */
final class NeonConnectionConfig
{
    private const SNI_MIN_LIBPQ = '14';

    /**
     * @param  array<string, mixed>  $connection
     * @param  string|null  $libpqVersion  injected in tests; defaults to the runtime PGSQL_LIBPQ_VERSION
     * @return array<string, mixed>
     */
    public static function apply(array $connection, ?string $libpqVersion = null): array
    {
        $libpqVersion ??= defined('PGSQL_LIBPQ_VERSION') ? (string) constant('PGSQL_LIBPQ_VERSION') : null;
        if ($libpqVersion === null || version_compare($libpqVersion, self::SNI_MIN_LIBPQ, '>=')) {
            return $connection;
        }

        $url = $connection['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return $connection;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (! str_ends_with($host, '.neon.tech')) {
            return $connection;
        }

        $endpoint = preg_replace('/-pooler$/', '', explode('.', $host)[0]);
        $password = rawurldecode($parts['pass'] ?? '');

        return array_merge($connection, [
            'url' => null,
            'host' => $host,
            'port' => $parts['port'] ?? 5432,
            'database' => ltrim($parts['path'] ?? '', '/'),
            'username' => rawurldecode($parts['user'] ?? ''),
            'password' => str_starts_with($password, 'endpoint=') ? $password : "endpoint={$endpoint};{$password}",
            'sslmode' => 'require',
        ]);
    }
}
