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

    public function test_the_synchronous_wait_is_declared_once_in_the_gateway(): void
    {
        $wait = new \ReflectionClassConstant(AiService::class, 'WAIT_SECONDS');

        $this->assertSame(AiGateway::class, $wait->getDeclaringClass()->getName());
        $this->assertSame(40, AiService::WAIT_SECONDS);
    }
}
