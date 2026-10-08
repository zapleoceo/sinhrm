<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Support;

/**
 * Result of OutboundUrlGuard::inspect(): either an error code, or the host/port plus the exact IPs the guard
 * approved. Callers pin the connection to those IPs (CURLOPT_RESOLVE) so a second DNS answer between the check
 * and the request cannot point the call at an internal address (DNS rebinding). The URL keeps the hostname, so
 * the Host header, SNI and certificate validation are unchanged.
 */
final readonly class PinnedTarget
{
    /** @param  list<string>  $ips */
    private function __construct(
        public ?string $error,
        public string $host,
        public int $port,
        public array $ips,
    ) {}

    public static function blocked(string $error): self
    {
        return new self($error, '', 0, []);
    }

    /** @param  list<string>  $ips */
    public static function approved(string $host, int $port, array $ips): self
    {
        return new self(null, $host, $port, $ips);
    }

    /**
     * HTTP client options for the approved target: the connection is bound to the checked IPs and redirects stay
     * off (a redirect would be a fresh, unpinned name). Use as `->withOptions($target->httpOptions())` — it
     * replaces the bare `['allow_redirects' => false]` every outbound caller used before.
     *
     * @return array{allow_redirects: false, curl: array<int, mixed>}
     */
    public function httpOptions(): array
    {
        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => $this->curlResolve(),
                CURLOPT_FOLLOWLOCATION => false,
            ],
        ];
    }

    /**
     * CURLOPT_RESOLVE entries ("host:port:ip[,ip…]", IPv6 bracketed); empty when the target was blocked.
     *
     * @return list<string>
     */
    public function curlResolve(): array
    {
        if ($this->error !== null || $this->ips === []) {
            return [];
        }
        $addresses = array_map(
            static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip,
            $this->ips,
        );

        return [$this->host.':'.$this->port.':'.implode(',', $addresses)];
    }
}
