<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Pdo\Mysql;
use Tests\TestCase;

/** Hardening items of the security audit 2026-10 («Info», docs/security/audit-2026-10.md) — kept by a test, not by memory. */
final class HardeningConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        unset($_SERVER['MYSQL_ATTR_SSL_CA'], $_ENV['MYSQL_ATTR_SSL_CA']);
        putenv('MYSQL_ATTR_SSL_CA');
        parent::tearDown();
    }

    /** Info: personal access tokens carry the sinhrm_ prefix, so secret scanners (SecretScrubber, GitHub) recognise them. */
    public function test_personal_access_tokens_carry_the_sinhrm_prefix(): void
    {
        $token = User::factory()->create()->createToken('audit')->plainTextToken;

        $this->assertMatchesRegularExpression('/^\d+\|sinhrm_[A-Za-z0-9]{40,}$/', $token);
    }

    /** Info: no signed storage/{path} route — files are served only by the modules (attachment + nosniff). */
    public function test_local_disk_is_not_served_over_http(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringStartsNotWith('storage/', $route->uri());
        }
    }

    /** Info: with a CA configured, the MySQL server certificate is verified too; without it nothing is forced. */
    public function test_mysql_tls_verifies_the_server_certificate_when_a_ca_is_set(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not loaded');
        }

        $this->assertSame([], $this->mysqlOptions());

        $_SERVER['MYSQL_ATTR_SSL_CA'] = $_ENV['MYSQL_ATTR_SSL_CA'] = '/etc/ssl/certs/test-ca.pem';
        putenv('MYSQL_ATTR_SSL_CA=/etc/ssl/certs/test-ca.pem');

        $this->assertSame([
            Mysql::ATTR_SSL_CA => '/etc/ssl/certs/test-ca.pem',
            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
        ], $this->mysqlOptions());
    }

    /** @return array<int, mixed> options of the mysql connection as config/database.php builds them from the environment */
    private function mysqlOptions(): array
    {
        /** @var array{connections: array{mysql: array{options: array<int, mixed>}}} $config */
        $config = require config_path('database.php');

        return $config['connections']['mysql']['options'];
    }
}
