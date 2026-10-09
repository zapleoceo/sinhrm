<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Support;

use App\Modules\Integrations\Contracts\HostResolver;

/**
 * SSRF pre-flight for every outbound call of a connection checker: https only, port 443 unless explicitly
 * allowed, and every resolved IP must be public. "Public" is an explicit CIDR blocklist (BLOCKED_V4/BLOCKED_V6),
 * not just FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE — those flags let CGNAT (100.64/10), benchmark (198.18/15),
 * IETF protocol assignments (192.0.0/24), multicast (224/4), IPv6 multicast (ff00::/8) and site-local (fec0::/10)
 * through. IPv6 forms that embed an IPv4 target (6to4 2002::/16, NAT64 64:ff9b::/96, IPv4-compatible ::/96,
 * IPv4-mapped ::ffff:0:0/96) are unwrapped and the embedded IPv4 is checked, so [::ffff:169.254.169.254] cannot
 * smuggle the metadata service past the filter. Returns an error code, or null when the URL is safe.
 *
 * Against DNS rebinding between the check and the request, use inspect(): it returns the approved IPs as a
 * PinnedTarget so the caller can pin the connection (CURLOPT_RESOLVE) instead of resolving the host a second time.
 */
final class OutboundUrlGuard
{
    /** IPv4 ranges that must never be dialled (RFC 1918/5735/6598/5737/2544 special-use + multicast/reserved). */
    private const array BLOCKED_V4 = [
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // CGNAT (RFC 6598) — often the provider's own infrastructure
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, incl. 169.254.169.254 cloud metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking (RFC 2544)
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, incl. 255.255.255.255 broadcast
    ];

    /** IPv6 ranges that must never be dialled (the embedded-IPv4 forms are handled separately). */
    private const array BLOCKED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '100::/64',           // discard-only
        '2001::/32',          // Teredo (tunnels to an arbitrary IPv4 endpoint)
        '2001:db8::/32',      // documentation
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'fec0::/10',          // deprecated site-local
        'ff00::/8',           // multicast
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /** @param  list<int>  $allowedPorts */
    public function check(string $url, array $allowedPorts = [443]): ?string
    {
        return $this->inspect($url, $allowedPorts)->error;
    }

    /**
     * Same checks as check(), but also hands back the host, port and the IPs that were approved, so the caller
     * can connect to exactly those addresses instead of resolving again.
     *
     * @param  list<int>  $allowedPorts
     */
    public function inspect(string $url, array $allowedPorts = [443]): PinnedTarget
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/\s/', $url) === 1) {
            return PinnedTarget::blocked('invalid_url');
        }
        $port = $parts['port'] ?? 443;
        if (! in_array($port, $allowedPorts, true)) {
            return PinnedTarget::blocked('blocked_port');
        }

        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);
        if ($ips === []) {
            return PinnedTarget::blocked('unresolved_host');
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return PinnedTarget::blocked('blocked_host');
            }
        }

        return PinnedTarget::approved($parts['host'], $port, $ips);
    }

    /** True only for a globally routable unicast address (see the class docblock for what is excluded). */
    public static function isPublicIp(string $ip): bool
    {
        $binary = inet_pton($ip);
        if ($binary === false) {
            return false;
        }
        if (strlen($binary) === 4) {
            return ! self::inAnyRange($binary, self::BLOCKED_V4);
        }

        $embedded = self::embeddedIpv4($binary);
        if ($embedded !== null) {
            return ! self::inAnyRange($embedded, self::BLOCKED_V4);
        }

        return ! self::inAnyRange($binary, self::BLOCKED_V6);
    }

    /**
     * The IPv4 address carried inside an IPv6 transition address (6to4, NAT64, IPv4-compatible, IPv4-mapped),
     * in packed form; null when the address carries none.
     */
    private static function embeddedIpv4(string $binary): ?string
    {
        $hex = bin2hex($binary);
        // 6to4 (2002::/16): the IPv4 is bytes 2–5.
        if (str_starts_with($hex, '2002')) {
            return substr($binary, 2, 4);
        }
        // NAT64 well-known prefix 64:ff9b::/96 — the IPv4 is the last 4 bytes.
        if (str_starts_with($hex, '0064ff9b0000000000000000')) {
            return substr($binary, 12, 4);
        }
        // IPv4-mapped ::ffff:0:0/96 and IPv4-compatible ::/96 (both hold the IPv4 in the last 4 bytes).
        if (str_starts_with($hex, '00000000000000000000ffff') || str_starts_with($hex, '000000000000000000000000')) {
            $embedded = substr($binary, 12, 4);

            // ::/128 and ::1 are not IPv4-compatible addresses; let the IPv6 blocklist judge them.
            return $embedded === "\0\0\0\0" || $embedded === "\0\0\0\1" ? null : $embedded;
        }

        return null;
    }

    /**
     * @param  string  $binary  packed address (inet_pton)
     * @param  list<string>  $ranges  CIDR notation, same family as $binary
     */
    private static function inAnyRange(string $binary, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$subnet, $bits] = explode('/', $range, 2);
            $network = (string) inet_pton($subnet);
            if (strlen($network) !== strlen($binary)) {
                continue;
            }
            $prefix = (int) $bits;
            $fullBytes = intdiv($prefix, 8);
            if ($fullBytes > 0 && strncmp($binary, $network, $fullBytes) !== 0) {
                continue;
            }
            $remainder = $prefix % 8;
            if ($remainder === 0) {
                return true;
            }
            $mask = 0xFF << (8 - $remainder) & 0xFF;
            if ((ord($binary[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
