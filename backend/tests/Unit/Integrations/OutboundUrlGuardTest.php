<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Modules\Integrations\Support\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHostResolver;

final class OutboundUrlGuardTest extends TestCase
{
    private function guard(): OutboundUrlGuard
    {
        return new OutboundUrlGuard(new FakeHostResolver([
            'internal.test' => ['10.1.2.3'],
            'mixed.test' => ['93.184.216.34', '192.168.1.1'],
            'metadata.test' => ['169.254.169.254'],
            'v6local.test' => ['fe80::1'],
            'nowhere.test' => [],
        ]));
    }

    public function test_public_https_host_passes(): void
    {
        $this->assertNull($this->guard()->check('https://api.example.test/v1/health'));
        $this->assertNull($this->guard()->check('https://api.example.test:8443/x', [443, 8443]));
    }

    /** @return array<string, array{string, string}> */
    public static function blocked(): array
    {
        return [
            'http scheme' => ['http://api.example.test/', 'invalid_url'],
            'no scheme' => ['api.example.test', 'invalid_url'],
            'credentials' => ['https://u:p@api.example.test/', 'invalid_url'],
            'whitespace' => ['https://api.example.test/a b', 'invalid_url'],
            'odd port' => ['https://api.example.test:8443/', 'blocked_port'],
            'loopback literal' => ['https://127.0.0.1/', 'blocked_host'],
            'rfc1918 literal' => ['https://172.16.0.5/', 'blocked_host'],
            'metadata literal' => ['https://169.254.169.254/latest', 'blocked_host'],
            'zero net' => ['https://0.0.0.0/', 'blocked_host'],
            'ipv6 loopback' => ['https://[::1]/', 'blocked_host'],
            'ipv6 unique local' => ['https://[fd00::1]/', 'blocked_host'],
            'ipv6 link local' => ['https://[fe80::1]/', 'blocked_host'],
            'ipv4-mapped ipv6' => ['https://[::ffff:127.0.0.1]/', 'blocked_host'],
            'resolves to private' => ['https://internal.test/', 'blocked_host'],
            'any private record' => ['https://mixed.test/', 'blocked_host'],
            'resolves to metadata' => ['https://metadata.test/', 'blocked_host'],
            'resolves to v6 link local' => ['https://v6local.test/', 'blocked_host'],
            'does not resolve' => ['https://nowhere.test/', 'unresolved_host'],
        ];
    }

    #[DataProvider('blocked')]
    public function test_rejects(string $url, string $code): void
    {
        $this->assertSame($code, $this->guard()->check($url));
    }

    /**
     * Special-use ranges that FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE lets through, plus IPv6 transition
     * addresses that embed an IPv4 target (6to4, NAT64, IPv4-compatible, IPv4-mapped).
     *
     * @return iterable<string, array{string}>
     */
    public static function specialUseIps(): iterable
    {
        yield 'cgnat 100.64/10 low' => ['100.64.0.1'];
        yield 'cgnat 100.64/10 high' => ['100.127.255.254'];
        yield 'benchmark 198.18/15' => ['198.19.0.1'];
        yield 'ietf protocol 192.0.0/24' => ['192.0.0.1'];
        yield 'test-net-1 192.0.2/24' => ['192.0.2.5'];
        yield 'test-net-2 198.51.100/24' => ['198.51.100.5'];
        yield 'test-net-3 203.0.113/24' => ['203.0.113.5'];
        yield 'multicast 224/4' => ['224.0.0.1'];
        yield 'multicast high' => ['239.255.255.250'];
        yield 'reserved 240/4' => ['240.0.0.1'];
        yield 'broadcast' => ['255.255.255.255'];
        yield 'ipv6 multicast ff00::/8' => ['ff02::1'];
        yield 'ipv6 site-local fec0::/10' => ['fec0::1'];
        yield 'nat64 to loopback' => ['64:ff9b::7f00:1'];
        yield 'nat64 to rfc1918' => ['64:ff9b::a00:1'];
        yield 'nat64 to metadata' => ['64:ff9b::a9fe:a9fe'];
        yield '6to4 to loopback' => ['2002:7f00:0001::1'];
        yield '6to4 to rfc1918' => ['2002:c0a8:0101::1'];
        yield '6to4 to metadata' => ['2002:a9fe:a9fe::1'];
        yield 'ipv4-compatible loopback' => ['::7f00:1'];
        yield 'ipv4-compatible rfc1918' => ['::a00:1'];
        yield 'ipv4-mapped rfc1918' => ['::ffff:10.0.0.1'];
        yield 'ipv4-mapped metadata' => ['::ffff:169.254.169.254'];
        yield 'teredo' => ['2001:0:c0a8:101::1'];
        yield 'ipv6 documentation' => ['2001:db8::1'];
        yield 'ipv6 unspecified' => ['::'];
        yield 'ipv6 discard-only 100::/64' => ['100::1'];
    }

    #[DataProvider('specialUseIps')]
    public function test_special_use_ranges_are_not_public(string $ip): void
    {
        $this->assertFalse(OutboundUrlGuard::isPublicIp($ip), $ip.' must not count as public');

        $url = str_contains($ip, ':') ? "https://[$ip]/hook" : "https://$ip/hook";
        $this->assertSame('blocked_host', $this->guard()->check($url));
        $this->assertSame('blocked_host', (new OutboundUrlGuard(new FakeHostResolver(['rebind.test' => [$ip]])))->check('https://rebind.test/hook'));
    }

    /** @return iterable<string, array{string}> */
    public static function publicIps(): iterable
    {
        yield 'ipv4' => ['93.184.216.34'];
        yield 'ipv4 just above cgnat' => ['100.128.0.1'];
        yield 'ipv4 just below cgnat' => ['100.63.255.255'];
        yield 'ipv4 just above benchmark' => ['198.20.0.1'];
        yield 'ipv6' => ['2606:4700:4700::1111'];
        yield '6to4 wrapping a public v4' => ['2002:5db8:d822::1'];
        yield 'nat64 wrapping a public v4' => ['64:ff9b::5db8:d822'];
        yield 'ipv4-mapped public' => ['::ffff:93.184.216.34'];
    }

    #[DataProvider('publicIps')]
    public function test_public_addresses_still_pass(string $ip): void
    {
        $this->assertTrue(OutboundUrlGuard::isPublicIp($ip), $ip.' must stay reachable');
    }

    public function test_inspect_returns_the_approved_ips_for_connection_pinning(): void
    {
        $target = $this->guard()->inspect('https://api.example.test/v1/hook');

        $this->assertNull($target->error);
        $this->assertSame('api.example.test', $target->host);
        $this->assertSame(443, $target->port);
        $this->assertSame(['93.184.216.34'], $target->ips);
        $this->assertSame(['api.example.test:443:93.184.216.34'], $target->curlResolve());

        $blocked = $this->guard()->inspect('https://internal.test/v1/hook');
        $this->assertSame('blocked_host', $blocked->error);
        $this->assertSame([], $blocked->ips);
        $this->assertSame([], $blocked->curlResolve());
    }

    public function test_inspect_brackets_ipv6_and_keeps_a_custom_port(): void
    {
        $guard = new OutboundUrlGuard(new FakeHostResolver(['v6.test' => ['2606:4700:4700::1111', '93.184.216.34']]));
        $target = $guard->inspect('https://v6.test:8443/hook', [443, 8443]);

        $this->assertNull($target->error);
        $this->assertSame(['v6.test:8443:[2606:4700:4700::1111],93.184.216.34'], $target->curlResolve());
    }

    /** parse_url() gives up on these (returns false or no host): refused before any resolution. */
    public function test_unparseable_url_is_invalid(): void
    {
        $this->assertSame('invalid_url', $this->guard()->check('https://:443/'));
        $this->assertSame('invalid_url', $this->guard()->check('https:///path'));
        $this->assertSame('invalid_url', $this->guard()->inspect('')->error);
    }

    /** A resolver answer that is not an IP address at all fails closed instead of being dialled. */
    public function test_non_ip_resolver_answer_is_blocked(): void
    {
        $this->assertFalse(OutboundUrlGuard::isPublicIp('not-an-ip'));
        $this->assertFalse(OutboundUrlGuard::isPublicIp(''));
        $this->assertFalse(OutboundUrlGuard::isPublicIp('999.1.1.1'));

        $guard = new OutboundUrlGuard(new FakeHostResolver(['garbage.test' => ['93.184.216.34', 'not-an-ip']]));
        $target = $guard->inspect('https://garbage.test/hook');
        $this->assertSame('blocked_host', $target->error);
        $this->assertSame([], $target->ips);
    }

    /** An IP literal is not resolved, so it is not pinned: no "[2606:4700::1]:443:…" entry curl would reject. */
    public function test_inspect_does_not_pin_an_ip_literal_host(): void
    {
        $v6 = $this->guard()->inspect('https://[2606:4700::1]/hook');
        $this->assertNull($v6->error);
        $this->assertSame(['2606:4700::1'], $v6->ips);
        $this->assertSame([], $v6->curlResolve());
        $this->assertSame([], $v6->httpOptions()['curl'][CURLOPT_RESOLVE]);

        $v4 = $this->guard()->inspect('https://93.184.216.34/hook');
        $this->assertNull($v4->error);
        $this->assertSame([], $v4->curlResolve());

        // A private literal is still refused by the guard itself.
        $this->assertSame('blocked_host', $this->guard()->inspect('https://[::1]/hook')->error);
    }
}
