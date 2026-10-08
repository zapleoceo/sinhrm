<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Modules\Ai\Support\AiSettingsReader;
use App\Modules\Integrations\Contracts\IntegrationConfigs;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\IntegrationStatus;
use Mockery\MockInterface;
use Tests\TestCase;

/** AI settings come from the Integrations contract IntegrationConfigs (no DB, no vault). */
final class AiSettingsReaderContractTest extends TestCase
{
    public function test_settings_are_read_from_the_integration_config(): void
    {
        /** @var IntegrationConfigs&MockInterface $configs */
        $configs = $this->mock(IntegrationConfigs::class);
        $configs->expects('load')->andReturn(new IntegrationConfig('ai_broker', ['base_url' => 'https://broker.example.test/'], ['project_key' => 'test-key']));
        $configs->expects('status')->with('ai_broker')->andReturn(IntegrationStatus::Demo);

        $settings = $this->app->make(AiSettingsReader::class)->read();

        $this->assertSame('https://broker.example.test', $settings->baseUrl);
        $this->assertTrue($settings->integrationOn);
    }
}
