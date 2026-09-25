<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Contracts\HealthCheck;
use App\Modules\Core\Services\HealthService;
use PHPUnit\Framework\TestCase;

final class HealthServiceTest extends TestCase
{
    public function test_ok_only_when_all_checks_pass(): void
    {
        $service = new HealthService([$this->check('a', true), $this->check('b', false)]);

        $report = $service->report();

        $this->assertFalse($report['ok']);
        $this->assertTrue($report['checks']['a']['ok']);
        $this->assertFalse($report['checks']['b']['ok']);
    }

    public function test_ok_with_no_checks(): void
    {
        $this->assertTrue((new HealthService([]))->report()['ok']);
    }

    private function check(string $name, bool $ok): HealthCheck
    {
        return new class($name, $ok) implements HealthCheck
        {
            public function __construct(private string $n, private bool $o) {}

            public function name(): string
            {
                return $this->n;
            }

            public function check(): array
            {
                return ['ok' => $this->o];
            }
        };
    }
}
