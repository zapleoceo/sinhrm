<?php

declare(strict_types=1);

namespace Tests\Unit\Channels;

use App\Modules\Channels\Adapters\ViberAdapter;
use App\Modules\Channels\Enums\ChannelMode;
use App\Modules\Channels\Services\ChannelContext;
use App\Modules\Integrations\Contracts\IntegrationConfigs;
use App\Modules\Integrations\Enums\IntegrationStatus;
use Mockery\MockInterface;
use Tests\TestCase;

/** A channel's mode follows the integration status from the Integrations contract IntegrationConfigs (no DB). */
final class ChannelContextTest extends TestCase
{
    public function test_mode_follows_the_integration_status(): void
    {
        $adapter = $this->app->make(ViberAdapter::class);
        /** @var IntegrationConfigs&MockInterface $configs */
        $configs = $this->mock(IntegrationConfigs::class);
        $configs->expects('status')->with($adapter->key())->twice()->andReturn(IntegrationStatus::Demo, IntegrationStatus::Error);

        $context = $this->app->make(ChannelContext::class);

        $this->assertSame(ChannelMode::Demo, $context->mode($adapter));
        $this->assertSame(ChannelMode::Live, $context->mode($adapter));
    }
}
