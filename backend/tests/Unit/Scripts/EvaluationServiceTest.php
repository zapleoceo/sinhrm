<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Modules\Integrations\Contracts\AiPolicy;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Scripts\Contracts\EvaluationRepository;
use App\Modules\Scripts\Contracts\ScriptRepository;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\EvaluationEngine;
use App\Modules\Scripts\Exceptions\ScriptException;
use App\Modules\Scripts\Services\AiScriptEvaluator;
use App\Modules\Scripts\Services\EvaluationService;
use App\Modules\Scripts\Services\RulesScriptEvaluator;
use PHPUnit\Framework\TestCase;

final class EvaluationServiceTest extends TestCase
{
    public function test_ai_evaluator_refuses_while_the_switch_is_off(): void
    {
        $this->expectException(ScriptException::class);
        $this->expectExceptionMessage('ai_disabled');
        (new AiScriptEvaluator($this->policy(false)))->evaluate(ScriptContent::empty(), 'text');
    }

    public function test_ai_evaluator_is_not_wired_even_when_switched_on(): void
    {
        $this->expectExceptionMessage('ai_not_configured');
        (new AiScriptEvaluator($this->policy(true)))->evaluate(ScriptContent::empty(), 'text');
    }

    public function test_service_uses_rules_when_ai_is_off_or_refuses(): void
    {
        foreach ([false, true] as $enabled) {
            $policy = $this->policy($enabled);
            $service = new EvaluationService(
                $policy,
                new AiScriptEvaluator($policy),
                new RulesScriptEvaluator,
                $this->createStub(ScriptRepository::class),
                $this->createStub(EvaluationRepository::class),
                $this->createStub(TouchpointRepository::class),
            );

            $this->assertSame(EvaluationEngine::Rules, $service->evaluate(ScriptContent::empty(), 'hello')->engine);
        }
    }

    private function policy(bool $enabled): AiPolicy
    {
        $policy = $this->createStub(AiPolicy::class);
        $policy->method('enabled')->willReturn($enabled);

        return $policy;
    }
}
