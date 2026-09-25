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
}
