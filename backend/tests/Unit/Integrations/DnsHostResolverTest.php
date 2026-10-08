<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Modules\Integrations\Contracts\HostResolver;
use App\Modules\Integrations\Support\DnsHostResolver;
use PHPUnit\Framework\TestCase;

/**
 * The production resolver. Only deterministic inputs: an IPv4 literal (gethostbynamel parses it without a lookup) and
 * a reserved .invalid name (RFC 6761: never resolves). The AAAA loop body needs a real AAAA record and is not covered
 * here — CI must not depend on the outside DNS. Assertions tolerate a resolver that answers nothing for the AAAA query.
 */
final class DnsHostResolverTest extends TestCase
{
    public function test_it_is_the_host_resolver_contract(): void
    {
        $this->assertInstanceOf(HostResolver::class, new DnsHostResolver);
    }

    public function test_ipv4_literal_resolves_to_itself(): void
    {
        $ips = (new DnsHostResolver)->resolve('127.0.0.1');

        $this->assertContains('127.0.0.1', $ips);
        $this->assertTrue(array_is_list($ips));
        $this->assertSame($ips, array_values(array_unique($ips)), 'no duplicates');
        foreach ($ips as $ip) {
            $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP), $ip.' must be an IP address');
        }
    }

    public function test_reserved_invalid_name_resolves_to_nothing(): void
    {
        $this->assertSame([], (new DnsHostResolver)->resolve('no-such-host.invalid'));
    }
}
