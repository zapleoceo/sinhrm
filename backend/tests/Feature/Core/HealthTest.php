<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Core\Contracts\HealthCheck;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_reports_ok_when_database_is_reachable(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('checks.database.ok', true);
    }

    public function test_health_returns_503_when_a_check_fails(): void
    {
        $this->app->tag([FailingCheck::class], HealthCheck::class);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('checks.failing.ok', false);
    }
}

final class FailingCheck implements HealthCheck
{
    public function name(): string
    {
        return 'failing';
    }

    public function check(): array
    {
        return ['ok' => false, 'detail' => 'simulated'];
    }
}
