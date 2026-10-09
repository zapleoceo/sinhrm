<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Modules\Integrations\Contracts\IntegrationConfigs;
use App\Modules\Integrations\Contracts\IntegrationSettings;
use App\Modules\Integrations\Services\IntegrationConfigLoader;
use App\Modules\Integrations\Services\IntegrationService;
use Tests\TestCase;

/** Ai and Channels read configs through IntegrationConfigs and change settings through IntegrationSettings. */
final class IntegrationContractsTest extends TestCase
{
    public function test_contracts_are_bound_to_the_integration_services(): void
    {
        $this->assertInstanceOf(IntegrationConfigLoader::class, $this->app->make(IntegrationConfigs::class));
        $this->assertInstanceOf(IntegrationService::class, $this->app->make(IntegrationSettings::class));
    }
}
