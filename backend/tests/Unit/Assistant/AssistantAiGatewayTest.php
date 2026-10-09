<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Modules\Ai\Contracts\AiGateway;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Assistant\Services\AssistantQuipService;
use Mockery\MockInterface;
use Tests\TestCase;

/** The assistant reaches AI only through the AiGateway contract: AI off → no quips and no model call (no DB). */
final class AssistantAiGatewayTest extends TestCase
{
    public function test_no_quips_when_the_gateway_says_ai_is_off(): void
    {
        /** @var AiGateway&MockInterface $ai */
        $ai = $this->mock(AiGateway::class);
        $ai->expects('available')->with(AiPurpose::AssistantQuips)->andReturnFalse();
        $ai->expects('run')->never();

        $quips = $this->app->make(AssistantQuipService::class)->quips('idle', 'uk');

        $this->assertSame(['jokes' => [], 'source' => 'none'], $quips);
    }
}
