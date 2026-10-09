<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Modules\Auth\Support\LocalTestLoginGuard;
use PHPUnit\Framework\TestCase;

final class LocalTestLoginGuardTest extends TestCase
{
    public function test_only_loopback_development_server_with_explicit_secret_can_enter(): void
    {
        $valid = static fn (string $env, string $sapi, ?string $ip, string $host, bool $enabled, string $given): bool => LocalTestLoginGuard::allows($env, $sapi, $ip, $host, $enabled, 'local-test-secret', $given);

        self::assertTrue($valid('local', 'cli-server', '127.0.0.1', '127.0.0.1', true, 'local-test-secret'));
        self::assertFalse($valid('production', 'cli-server', '127.0.0.1', '127.0.0.1', true, 'local-test-secret'));
        self::assertFalse($valid('staging', 'cli-server', '127.0.0.1', '127.0.0.1', true, 'local-test-secret'));
        self::assertFalse($valid('local', 'fpm-fcgi', '127.0.0.1', '127.0.0.1', true, 'local-test-secret'));
        self::assertFalse($valid('local', 'cli-server', '192.0.2.1', '127.0.0.1', true, 'local-test-secret'));
        self::assertFalse($valid('local', 'cli-server', '127.0.0.1', 'example.test', true, 'local-test-secret'));
        self::assertFalse($valid('local', 'cli-server', '127.0.0.1', '127.0.0.1', false, 'local-test-secret'));
        self::assertFalse($valid('local', 'cli-server', '127.0.0.1', '127.0.0.1', true, 'wrong-secret'));
        self::assertFalse(LocalTestLoginGuard::allows('local', 'cli-server', '127.0.0.1', '127.0.0.1', true, '', ''));
    }
}
