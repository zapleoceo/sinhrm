<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Scripts\Ai\AiEvaluationMapper;
use App\Modules\Scripts\Ai\ScriptEvaluationPrompt;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\EvaluationEngine;
use App\Modules\Scripts\Support\ScriptScore;
use PHPUnit\Framework\TestCase;

/** The AI side of the evaluation that does not need the container: score, mapping and parsing (script_eval.v1). */
final class EvaluationServiceTest extends TestCase
{
    public function test_score_uses_weights_then_share_of_done_steps(): void
    {
        $this->assertSame(75, ScriptScore::compute([['weight' => 30, 'done' => true], ['weight' => 10, 'done' => false], ['weight' => 0, 'done' => true], ['weight' => 0, 'done' => false]]));
        $this->assertSame(50, ScriptScore::compute([['weight' => 0, 'done' => true], ['weight' => 0, 'done' => false]]));
        $this->assertSame(0, ScriptScore::compute([]));
    }

    public function test_ai_answer_is_mapped_with_a_server_side_score_and_ignores_unknown_ids(): void
    {
        $data = AiEvaluationMapper::parse([
            'steps' => [
                ['id' => 'greet', 'done' => true, 'quote' => 'Добрий день', 'note' => 'Представився.'],
                ['id' => 'invite', 'done' => false, 'quote' => 'ignored', 'note' => 'Не запросив.'],
                ['id' => 'ghost', 'done' => true, 'quote' => 'x', 'note' => 'нема в скрипті'],
            ],
            'handled' => ['far', 'unknown'],
            'next' => false,
            'next_quote' => null,
            'tips' => ['Назвіть дату співбесіди.', ''],
            // The model must not score; a score in the answer is ignored.
            'score' => 100,
        ]);
        $result = AiEvaluationMapper::toResult($this->script(), $data);

        $this->assertSame(EvaluationEngine::Ai, $result->engine);
        $this->assertSame(40, $result->score);
        $this->assertSame(['greet', 'invite'], array_column($result->steps, 'id'));
        $this->assertNull($result->steps[1]['quote']);
        $this->assertSame('Не запросив.', $result->steps[1]['comment']);
        $this->assertTrue($result->objections[0]['handled'] ?? false);
        $this->assertSame(['missed_step', 'next_step_not_fixed', 'ai_tip'], array_column($result->recommendations, 'type'));
        $this->assertSame('Назвіть дату співбесіди.', $result->recommendations[2]['text'] ?? null);
    }

    public function test_quotes_must_be_verbatim_and_tips_are_capped(): void
    {
        $data = AiEvaluationMapper::parse([
            'steps' => [
                ['id' => 'greet', 'done' => true, 'quote' => 'добрий   ДЕНЬ', 'note' => 'ok'],
                ['id' => 'invite', 'done' => true, 'quote' => 'Запрошую на співбесіту', 'note' => 'ok'],
            ],
            'handled' => [], 'next' => true, 'next_quote' => 'не було такого', 'tips' => ['a', 'b', 'c'],
        ]);
        $result = AiEvaluationMapper::toResult($this->script(), $data, 'Добрий день! Запрошую на співбесіду завтра.');

        $this->assertSame('добрий   ДЕНЬ', $result->steps[0]['quote'], 'whitespace/case differences are tolerated');
        $this->assertNull($result->steps[1]['quote'], 'a typo is not a quote');
        $this->assertNull($result->nextStep['quote']);
        $this->assertCount(2, array_filter($result->recommendations, static fn (array $r): bool => $r['type'] === 'ai_tip'));
    }

    public function test_invalid_shapes_are_rejected(): void
    {
        foreach ([
            [],
            ['steps' => 'x', 'handled' => [], 'next' => true, 'next_quote' => null, 'tips' => []],
            ['steps' => [['id' => 'a', 'done' => 'yes']], 'handled' => [], 'next' => true, 'next_quote' => null, 'tips' => []],
            ['steps' => [], 'handled' => [], 'next' => 'true', 'next_quote' => null, 'tips' => []],
        ] as $json) {
            try {
                AiEvaluationMapper::parse($json);
                $this->fail('Expected InvalidAiOutput');
            } catch (InvalidAiOutput) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_system_prompt_is_stable_and_the_transcript_goes_last(): void
    {
        $first = ScriptEvaluationPrompt::build($this->script(), 'Розмова один, 2026-10-08 10:00, кандидат #512');
        $second = ScriptEvaluationPrompt::build($this->script(), 'Зовсім інша розмова');

        $this->assertSame($first->system, $second->system);
        $this->assertStringStartsWith('ROLE: '.ScriptEvaluationPrompt::ROLE, $first->system);
        $this->assertStringContainsString('SCRIPT: {"steps":[{"id":"greet"', $first->system);
        $this->assertStringNotContainsString('2026', $first->system);
        $this->assertStringContainsString('#512', $first->user);
        $this->assertSame(['system', 'user'], array_column($first->messages(), 'role'));
    }

    private function script(): ScriptContent
    {
        return ScriptContent::fromArray([
            'steps' => [
                ['id' => 'greet', 'title' => 'Привітання', 'goal' => 'Представитися', 'sample' => 'Добрий день', 'required' => true, 'weight' => 40],
                ['id' => 'invite', 'title' => 'Запрошення', 'goal' => 'Запросити', 'sample' => 'Запрошую', 'required' => true, 'weight' => 60],
            ],
            'objections' => [['id' => 'far', 'trigger' => 'далеко', 'answer' => 'Компенсуємо проїзд']],
        ]);
    }
}
