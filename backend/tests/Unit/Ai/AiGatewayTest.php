<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Modules\Ai\Contracts\AiGateway;
use App\Modules\Ai\Services\AiService;
use Tests\TestCase;

/** Other modules reach AI through AiGateway, and only AiService implements it (CLAUDE.md rule 7). */
final class AiGatewayTest extends TestCase
{
    public function test_the_gateway_is_the_ai_service(): void
    {
        $this->assertInstanceOf(AiService::class, $this->app->make(AiGateway::class));
    }

    public function test_the_synchronous_wait_is_the_same_on_both(): void
    {
        $this->assertSame(AiService::WAIT_SECONDS, AiGateway::WAIT_SECONDS);
    }
}
