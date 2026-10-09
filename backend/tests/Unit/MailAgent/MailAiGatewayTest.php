<?php

declare(strict_types=1);

namespace Tests\Unit\MailAgent;

use App\Modules\Ai\Contracts\AiGateway;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\MailAgent\Services\AiMailClassifier;
use Mockery\MockInterface;
use Tests\TestCase;

/** The mail classifier reaches AI only through the AiGateway contract: AI off → nothing is submitted (no DB). */
final class MailAiGatewayTest extends TestCase
{
    public function test_nothing_is_classified_when_ai_is_off(): void
    {
        /** @var AiGateway&MockInterface $ai */
        $ai = $this->mock(AiGateway::class);
        $ai->expects('available')->with(AiPurpose::MailClassification)->andReturnFalse();
        $ai->expects('run')->never();

        $this->assertSame(0, $this->app->make(AiMailClassifier::class)->classifyQueued(10));
    }
}
