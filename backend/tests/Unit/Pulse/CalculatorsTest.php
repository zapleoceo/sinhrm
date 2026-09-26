<?php

declare(strict_types=1);

namespace Tests\Unit\Pulse;

use App\Modules\Pulse\Enums\WaveSchedule;
use App\Modules\Pulse\Exceptions\PulseException;
use App\Modules\Pulse\Support\AnswerValidator;
use App\Modules\Pulse\Support\Enps;
use App\Modules\Pulse\Support\MoodStats;
use App\Modules\Pulse\Support\RespondentHash;
use App\Modules\Pulse\Support\WaveResults;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Pure Pulse calculators: eNPS, wave results, mood buckets, answer validation, respondent hash, schedules. */
final class CalculatorsTest extends TestCase
{
    public function test_enps_boundaries(): void
    {
        $this->assertEquals(['score' => null, 'promoters' => 0, 'passives' => 0, 'detractors' => 0, 'total' => 0], Enps::calculate([]));
        // 9 and 10 promote, 7–8 are passive, 0–6 detract.
        $this->assertEquals(['score' => 0, 'promoters' => 2, 'passives' => 2, 'detractors' => 2, 'total' => 6], Enps::calculate([10, 9, 8, 7, 6, 0]));
        $this->assertSame(100, Enps::calculate([9, 10])['score']);
        $this->assertSame(-100, Enps::calculate([6, 0])['score']);
    }

    public function test_enps_rounding(): void
    {
        $this->assertSame(67, Enps::calculate([10, 10, 8])['score']);
        $this->assertSame(-33, Enps::calculate([10, 5, 5])['score']);
    }

    public function test_wave_summary_and_headline(): void
    {
        $questions = [
            ['id' => 's', 'type' => 'scale10', 'text' => 'S'],
            ['id' => 'e', 'type' => 'enps', 'text' => 'E'],
            ['id' => 't', 'type' => 'text', 'text' => 'T'],
        ];
        $answers = [['s' => 10, 'e' => 10, 't' => 'b'], ['s' => 5, 'e' => 0, 't' => ' a '], ['s' => 6, 'e' => 9]];
        $this->assertEquals(['responses' => null, 'suppressed' => true, 'questions' => []], WaveResults::summary($questions, $answers, 5));

        $summary = WaveResults::summary($questions, $answers, 3);
        $this->assertSame(3, $summary['responses']);
        $this->assertSame(7.0, $summary['questions'][0]['average']);
        $this->assertSame(1, $summary['questions'][0]['distribution']['10']);
        $this->assertSame(33, $summary['questions'][1]['enps']['score']);
        $this->assertTrue($summary['questions'][2]['suppressed'], 'text answered by 2 < 3 is hidden');
        $this->assertArrayNotHasKey('texts', $summary['questions'][2]);
        $this->assertSame(['a', 'b'], WaveResults::summary($questions, $answers, 2)['questions'][2]['texts']);
        $this->assertSame(7.0, WaveResults::headline($questions[0], $answers));
        $this->assertSame(33.0, WaveResults::headline($questions[1], $answers));
        $this->assertNull(WaveResults::headline($questions[2], $answers));
        $this->assertNull(WaveResults::headline($questions[0], []));
    }

    public function test_mood_bucket_counts_people_once(): void
    {
        $this->assertTrue(MoodStats::bucket([['employee_id' => 1, 'score' => 5]], 2)['suppressed']);
        $stats = MoodStats::bucket([
            ['employee_id' => 1, 'score' => 5], ['employee_id' => 1, 'score' => 5], ['employee_id' => 1, 'score' => 5],
            ['employee_id' => 2, 'score' => 1],
        ], 2);
        $this->assertSame(2, $stats['respondents']);
        $this->assertSame(3.0, $stats['average'], 'person means 5 and 1, not a raw 4.0');
        $this->assertSame(3, $stats['distribution'][5]);
    }

    public function test_answer_validation(): void
    {
        $questions = [
            ['id' => 'n', 'type' => 'scale5', 'text' => 'N', 'required' => true],
            ['id' => 'one', 'type' => 'single', 'text' => 'O', 'options' => ['a', 'b']],
            ['id' => 'many', 'type' => 'multi', 'text' => 'M', 'options' => ['a', 'b', 'c']],
            ['id' => 'txt', 'type' => 'text', 'text' => 'T'],
        ];
        $this->assertSame(
            ['n' => 5, 'one' => 1, 'many' => [0, 2], 'txt' => 'hi'],
            AnswerValidator::validate($questions, ['n' => '5', 'one' => 1, 'many' => [2, 0, 2], 'txt' => ' hi ', 'unknown' => 1]),
        );
        $this->assertSame(['n' => 1], AnswerValidator::validate($questions, ['n' => 1, 'txt' => '   ']));
        try {
            AnswerValidator::validate($questions, ['one' => 2, 'many' => [3], 'txt' => ['x']]);
            $this->fail('expected invalid_answers');
        } catch (PulseException $e) {
            $this->assertSame('invalid_answers', $e->errorCode);
            $this->assertSame(['n', 'one', 'many', 'txt'], $e->extra['questions']);
        }
    }

    public function test_respondent_hash_depends_on_salt_and_needs_it(): void
    {
        $hash = new RespondentHash('synthetic-app-key');
        $a = $hash->for('salt-one', 7);
        $this->assertSame($a, $hash->for('salt-one', 7));
        $this->assertNotSame($a, $hash->for('salt-two', 7));
        $this->assertNotSame($a, $hash->for('salt-one', 8));
        $this->expectException(RuntimeException::class);
        $hash->for(null, 7);
    }

    public function test_schedules(): void
    {
        $start = Carbon::parse('2026-01-31 09:00:00');
        $this->assertNull(WaveSchedule::Once->next($start));
        $this->assertSame('2026-02-07', WaveSchedule::Weekly->next($start)?->toDateString());
        $this->assertSame('2026-02-28', WaveSchedule::Monthly->next($start)?->toDateString());
        $this->assertSame('2026-04-30', WaveSchedule::Quarterly->next($start)?->toDateString());
    }
}
