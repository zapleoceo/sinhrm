<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Support\NeonConnectionConfig;
use PHPUnit\Framework\TestCase;

final class NeonConnectionConfigTest extends TestCase
{
    public function test_injects_endpoint_id_for_neon_pooler_url(): void
    {
        $config = NeonConnectionConfig::apply([
            'driver' => 'pgsql',
            'url' => 'postgresql://app_user:p%40ss@ep-cool-sun-123-pooler.eu-central-1.aws.neon.tech/neondb?sslmode=require',
        ]);

        $this->assertNull($config['url']);
        $this->assertSame('ep-cool-sun-123-pooler.eu-central-1.aws.neon.tech', $config['host']);
        $this->assertSame('neondb', $config['database']);
        $this->assertSame('app_user', $config['username']);
        $this->assertSame('endpoint=ep-cool-sun-123;p@ss', $config['password']);
        $this->assertSame('require', $config['sslmode']);
        $this->assertSame('pgsql', $config['driver']);
    }

    public function test_leaves_non_neon_and_empty_urls_untouched(): void
    {
        $local = ['url' => 'postgresql://u:p@127.0.0.1:5432/app'];
        $this->assertSame($local, NeonConnectionConfig::apply($local));
        $this->assertSame(['url' => null], NeonConnectionConfig::apply(['url' => null]));
    }

    public function test_does_not_double_prefix(): void
    {
        $config = NeonConnectionConfig::apply(['url' => 'postgresql://u:endpoint%3Dep-x%3Bp@ep-x.aws.neon.tech/db']);

        $this->assertSame('endpoint=ep-x;p', $config['password']);
    }
}
