<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Neon routes connections by SNI. The libpq bundled with the vercel-php runtime has no SNI support,
 * so Neon rejects it with "Endpoint ID is not specified". Neon's documented workaround: pass the
 * endpoint id inside the password as "endpoint=<id>;<password>" (https://neon.tech/sni).
 *
 * Input: a pgsql connection config with a postgres:// URL. Output: the same config with explicit
 * host/port/database/username/password and the endpoint id injected. Non-Neon URLs are returned unchanged.
 */
final class NeonConnectionConfig
{
    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    public static function apply(array $connection): array
    {
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
