<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Services\RulesScriptEvaluator;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RulesScriptEvaluatorTest extends TestCase
{
    private RulesScriptEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new RulesScriptEvaluator;
    }

    public function test_weighted_score_quotes_and_missed_required_steps(): void
    {
        $script = ScriptContent::fromArray(['steps' => [
            ['id' => 'a', 'title' => 'Greet', 'required' => true, 'weight' => 10, 'keywords' => ['Добрий день']],
            ['id' => 'b', 'title' => 'Offer', 'required' => true, 'weight' => 60, 'keywords' => ['співбесід']],
            ['id' => 'c', 'title' => 'Extra', 'required' => false, 'weight' => 30, 'keywords' => ['бонус']],
        ]]);

        $result = $this->evaluator->evaluate($script, "ДОБРИЙ ДЕНЬ, Олено! Пропоную онлайн-співбесіду.\nЗавтра о 10:00 підходить?");

        $this->assertSame(70, $result->score);
        $this->assertSame([true, true, false], array_column($result->steps, 'done'));
        $this->assertSame('ДОБРИЙ ДЕНЬ, Олено!', $result->steps[0]['quote']);
        $this->assertSame('Пропоную онлайн-співбесіду.', $result->steps[1]['quote']);
        $this->assertNull($result->steps[2]['quote']);
        $this->assertTrue($result->nextStep['fixed']);
        $this->assertSame('Завтра о 10:00 підходить?', $result->nextStep['quote']);
        $this->assertSame([], $result->recommendations);
    }

    public function test_missed_required_step_and_negative_phrase_last_means_not_fixed(): void
    {
        $script = ScriptContent::fromArray(['steps' => [
            ['id' => 'a', 'title' => 'Offer', 'required' => true, 'weight' => 50, 'keywords' => ['співбесід']],
        ]]);

        $result = $this->evaluator->evaluate($script, 'Можемо завтра поговорити. Але ви подумайте ще, добре?');

        $this->assertSame(0, $result->score);
        $this->assertFalse($result->nextStep['fixed']);
        $this->assertSame('Але ви подумайте ще, добре?', $result->nextStep['negative_quote']);
        $this->assertSame(['missed_step', 'next_step_not_fixed', 'negative_phrase'], array_column($result->recommendations, 'type'));
        $this->assertSame('Offer', $result->recommendations[0]['title']);
    }

    public function test_positive_after_negative_counts_as_fixed(): void
    {
        $result = $this->evaluator->evaluate(ScriptContent::empty(), 'Подумайте до вечора. Домовились, записала вас на завтра.');

        $this->assertTrue($result->nextStep['fixed']);
        $this->assertSame(0, $result->score);
    }

    public function test_zero_weights_fall_back_to_share_of_steps_and_empty_script_scores_zero(): void
    {
        $script = ScriptContent::fromArray(['steps' => [
            ['id' => 'a', 'title' => 'A', 'weight' => 0, 'keywords' => ['alpha']],
            ['id' => 'b', 'title' => 'B', 'weight' => 0, 'keywords' => ['beta']],
            ['id' => 'c', 'title' => 'C', 'weight' => 0, 'keywords' => []],
        ]]);

        $this->assertSame(67, $this->evaluator->evaluate($script, 'alpha and beta')->score);
        $this->assertSame(0, $this->evaluator->evaluate(ScriptContent::empty(), 'anything')->score);
    }

    public function test_objections_custom_patterns_and_long_quotes(): void
    {
        $script = ScriptContent::fromArray([
            'objections' => [['id' => 'o', 'trigger' => 'Дуже довго', 'answer' => 'Це швидше, ніж здається']],
            'next_step_patterns' => ['positive' => ['о \d{1,2}:\d{2}'], 'negative' => []],
            'steps' => [['id' => 's', 'title' => 'Long', 'weight' => 1, 'keywords' => ['x']]],
        ]);
        $long = 'x'.str_repeat('y', 400);

        $result = $this->evaluator->evaluate($script, "Кандидат: це дуже довго.\nЧекаємо о 9:30.\n$long");

        $this->assertTrue($result->objections[0]['raised']);
        $this->assertSame('Кандидат: це дуже довго.', $result->objections[0]['quote']);
        $this->assertTrue($result->nextStep['fixed']);
        $this->assertSame(300, mb_strlen((string) $result->steps[0]['quote']));
        $this->assertStringEndsWith('…', (string) $result->steps[0]['quote']);
    }

    public function test_pattern_validation_and_sentences(): void
    {
        $this->assertTrue(RulesScriptEvaluator::isValidPattern('записал'));
        $this->assertTrue(RulesScriptEvaluator::isValidPattern('a~b'));
        $this->assertFalse(RulesScriptEvaluator::isValidPattern('(open'));
        foreach (['(a+)+', '(a*)*', '(.*)+', '(\w+\s?)*$', '(x{2,})+', '(?:ab+){3,}'] as $evil) {
            $this->assertFalse(RulesScriptEvaluator::isValidPattern($evil), $evil);
        }
        $this->assertTrue(RulesScriptEvaluator::isValidPattern('о \d{1,2}:\d{2}'));
        $this->assertTrue(RulesScriptEvaluator::isValidPattern('(завтра|сьогодні)'));
        $this->assertTrue(RulesScriptEvaluator::isValidPattern(str_repeat('a', 200)));
        $this->assertFalse(RulesScriptEvaluator::isValidPattern(str_repeat('a', 201)));
        $this->assertSame(['One.', 'Two!', 'Three', 'Four?'], RulesScriptEvaluator::sentences("One. Two!\n\nThree\r\nFour?  "));
    }

    public function test_catastrophic_pattern_is_no_match_and_limit_is_restored(): void
    {
        $log = new class extends AbstractLogger
        {
            /** @var list<array<string, mixed>> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        Log::swap($log);
        $before = ini_get('pcre.backtrack_limit');
        // Stored before validation existed: evaluation must survive it.
        $script = ScriptContent::fromArray(['next_step_patterns' => ['positive' => ['(a+)+$'], 'negative' => []]]);

        $result = $this->evaluator->evaluate($script, str_repeat('a', 5000).'!');

        $this->assertFalse($result->nextStep['fixed']);
        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
        $this->assertSame('scripts.pattern_limit', $log->records[0]['message']);
        $this->assertSame('pattern_limit', $log->records[0]['context']['code']);
        $this->assertStringNotContainsString('aaaa', json_encode($log->records) ?: '');
        Log::clearResolvedInstances();
    }

    public function test_default_next_step_patterns_are_applied(): void
    {
        $content = ScriptContent::fromArray(['next_step_patterns' => ['positive' => null]]);
        $this->assertSame(ScriptContent::DEFAULT_POSITIVE, $content->nextStepPatterns['positive']);
        $this->assertSame(ScriptContent::DEFAULT_NEGATIVE, $content->nextStepPatterns['negative']);
        $this->assertSame([], ScriptContent::fromArray(['next_step_patterns' => ['negative' => []]])->nextStepPatterns['negative']);
    }

    #[DataProvider('touches')]
    public function test_which_touches_are_evaluated(Channel $channel, Direction $direction, ?string $body, ?ScriptChannel $expected): void
    {
        $this->assertSame($expected, ScriptChannel::forTouch($channel, $direction, $body));
    }

    /** @return array<string, array{Channel, Direction, string|null, ScriptChannel|null}> */
    public static function touches(): array
    {
        $long = str_repeat('a', 201);

        return [
            'call with transcript' => [Channel::Call, Direction::In, 'text', ScriptChannel::Call],
            'call without text' => [Channel::Call, Direction::Out, '  ', null],
            'long outbound telegram' => [Channel::Telegram, Direction::Out, $long, ScriptChannel::Chat],
            'exactly 200 chars' => [Channel::Viber, Direction::Out, str_repeat('a', 200), null],
            'inbound chat' => [Channel::Whatsapp, Direction::In, $long, null],
            'email' => [Channel::Email, Direction::Out, $long, null],
            'note' => [Channel::Note, Direction::Out, $long, null],
        ];
    }
}
