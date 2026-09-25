<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Support;

use App\Modules\Integrations\Contracts\HostResolver;

/**
 * SSRF pre-flight for every outbound call of a connection checker: https only, port 443 unless explicitly
 * allowed, and every resolved IP must be public (no loopback, RFC1918, link-local incl. 169.254.169.254
 * metadata, ::1, fc00::/7, fe80::/10, reserved ranges). Returns an error code, or null when the URL is safe.
 *
 * Note: resolution happens before the request, so DNS rebinding between the check and the call is not
 * prevented; redirects are disabled by the checkers so a public host cannot bounce the call inward.
 */
final class OutboundUrlGuard
{
    public function __construct(private readonly HostResolver $resolver) {}

    /** @param  list<int>  $allowedPorts */
    public function check(string $url, array $allowedPorts = [443]): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/\s/', $url) === 1) {
            return 'invalid_url';
        }
        if (! in_array($parts['port'] ?? 443, $allowedPorts, true)) {
            return 'blocked_port';
        }

        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);
        if ($ips === []) {
            return 'unresolved_host';
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return 'blocked_host';
            }
        }

        return null;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $bin = (string) inet_pton($ip);
            $first = ord($bin[0]);
            $second = ord($bin[1]);
            // ::1 / ::, fc00::/7 (unique local), fe80::/10 (link-local), IPv4-mapped (::ffff:0:0/96) — explicit.
            if (trim(bin2hex(substr($bin, 0, 15)), '0') === '' || ($first & 0xFE) === 0xFC
                || ($first === 0xFE && ($second & 0xC0) === 0x80)
                || str_starts_with(bin2hex($bin), '00000000000000000000ffff')) {
                return false;
            }
        } elseif (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.') || str_starts_with($ip, '0.')) {
            return false;
        }

        return true;
    }
}
