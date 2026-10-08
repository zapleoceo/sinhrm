<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Transfer;

use App\Modules\Core\Transfer\LaunchGuard;
use PHPUnit\Framework\TestCase;

final class LaunchGuardTest extends TestCase
{
    public function test_production_flag_is_required_for_production_env_or_remote_hosts(): void
    {
        $this->assertFalse(LaunchGuard::needsProductionFlag('testing', ['127.0.0.1', 'localhost']));
        $this->assertTrue(LaunchGuard::needsProductionFlag('production', ['127.0.0.1', '127.0.0.1']));
        $this->assertTrue(LaunchGuard::needsProductionFlag('local', ['ep-x.eu-central-1.aws.neon.tech', '127.0.0.1']));
        $this->assertTrue(LaunchGuard::needsProductionFlag('local', ['127.0.0.1', 'mysql.internal']));
    }

    public function test_loopback_aliases_are_one_host(): void
    {
        foreach (['localhost', '127.0.0.1', '::1', '[::1]', '127.0.1.1', 'LOCALHOST', ''] as $alias) {
            $this->assertSame('loopback', LaunchGuard::normalizeHost($alias), $alias);
        }
        $this->assertSame('mysql.internal', LaunchGuard::normalizeHost('MySQL.internal'));
        $this->assertFalse(LaunchGuard::needsProductionFlag('testing', ['[::1]', '127.0.1.1']));
    }

    public function test_write_is_confirmed_only_by_the_exact_target_database_name(): void
    {
        $this->assertTrue(LaunchGuard::confirmed('sinhrm', 'sinhrm'));
        $this->assertTrue(LaunchGuard::confirmed(' sinhrm ', 'sinhrm'));
        $this->assertFalse(LaunchGuard::confirmed(null, 'sinhrm'));
        $this->assertFalse(LaunchGuard::confirmed('yes', 'sinhrm'));
        $this->assertFalse(LaunchGuard::confirmed('', ''));
    }
}
