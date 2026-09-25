<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Support\NeonConnectionConfig;
use PHPUnit\Framework\TestCase;

final class NeonConnectionConfigTest extends TestCase
{
    private const OLD_LIBPQ = '13.9';

    private const NEW_LIBPQ = '16.4';

    public function test_injects_endpoint_id_when_libpq_has_no_sni(): void
    {
        $config = NeonConnectionConfig::apply([
            'driver' => 'pgsql',
            'url' => 'postgresql://app_user:p%40ss@ep-cool-sun-123-pooler.eu-central-1.aws.neon.tech/neondb?sslmode=require',
        ], self::OLD_LIBPQ);

        $this->assertNull($config['url']);
        $this->assertSame('ep-cool-sun-123-pooler.eu-central-1.aws.neon.tech', $config['host']);
        $this->assertSame('neondb', $config['database']);
        $this->assertSame('app_user', $config['username']);
        $this->assertSame('endpoint=ep-cool-sun-123;p@ss', $config['password']);
        $this->assertSame('require', $config['sslmode']);
        $this->assertSame('pgsql', $config['driver']);
    }

    public function test_leaves_config_untouched_when_libpq_supports_sni(): void
    {
        $neon = ['url' => 'postgresql://u:p@ep-x.aws.neon.tech/db'];

        $this->assertSame($neon, NeonConnectionConfig::apply($neon, self::NEW_LIBPQ));
    }

    public function test_leaves_non_neon_and_empty_urls_untouched(): void
    {
        $local = ['url' => 'postgresql://u:p@127.0.0.1:5432/app'];
        $this->assertSame($local, NeonConnectionConfig::apply($local, self::OLD_LIBPQ));
        $this->assertSame(['url' => null], NeonConnectionConfig::apply(['url' => null], self::OLD_LIBPQ));
    }

    public function test_does_not_double_prefix(): void
    {
        $config = NeonConnectionConfig::apply(['url' => 'postgresql://u:endpoint%3Dep-x%3Bp@ep-x.aws.neon.tech/db'], self::OLD_LIBPQ);

        $this->assertSame('endpoint=ep-x;p', $config['password']);
    }
}
