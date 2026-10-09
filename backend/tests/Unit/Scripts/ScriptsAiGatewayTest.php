<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Modules\Ai\Contracts\AiGateway;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Scripts\Services\AiScriptEvaluator;
use Mockery\MockInterface;
use Tests\TestCase;

/** The AI script evaluator asks the AiGateway contract whether its purpose is on (no DB). */
final class ScriptsAiGatewayTest extends TestCase
{
    public function test_availability_follows_the_gateway(): void
    {
        /** @var AiGateway&MockInterface $ai */
        $ai = $this->mock(AiGateway::class);
        $ai->expects('available')->with(AiPurpose::ScriptEvaluation)->twice()->andReturn(true, false);

        $evaluator = $this->app->make(AiScriptEvaluator::class);

        $this->assertTrue($evaluator->available());
        $this->assertFalse($evaluator->available());
    }
}
