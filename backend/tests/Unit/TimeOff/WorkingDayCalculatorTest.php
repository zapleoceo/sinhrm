<?php

declare(strict_types=1);

namespace Tests\Unit\TimeOff;

use App\Modules\TimeOff\Enums\HalfDay;
use App\Modules\TimeOff\Support\WorkingDayCalculator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 2026-10-12 is a Monday. */
final class WorkingDayCalculatorTest extends TestCase
{
    /** @return iterable<string, array{string, string, HalfDay, list<string>, float}> */
    public static function cases(): iterable
    {
        yield 'one full week' => ['2026-10-12', '2026-10-18', HalfDay::None, [], 5.0];
        yield 'two weeks across a weekend' => ['2026-10-12', '2026-10-23', HalfDay::None, [], 10.0];
        yield 'weekend only' => ['2026-10-17', '2026-10-18', HalfDay::None, [], 0.0];
        yield 'friday to monday' => ['2026-10-16', '2026-10-19', HalfDay::None, [], 2.0];
        yield 'holiday midweek' => ['2026-10-12', '2026-10-16', HalfDay::None, ['2026-10-14'], 4.0];
        yield 'holiday on a weekend changes nothing' => ['2026-10-12', '2026-10-18', HalfDay::None, ['2026-10-17'], 5.0];
        yield 'holiday outside the range' => ['2026-10-12', '2026-10-16', HalfDay::None, ['2026-10-20'], 5.0];
        yield 'half day at start' => ['2026-10-12', '2026-10-16', HalfDay::Start, [], 4.5];
        yield 'half day at end' => ['2026-10-12', '2026-10-16', HalfDay::End, [], 4.5];
        yield 'one half day' => ['2026-10-12', '2026-10-12', HalfDay::End, [], 0.5];
        yield 'half day on a weekend edge' => ['2026-10-12', '2026-10-18', HalfDay::End, [], 5.0];
        yield 'half day on a holiday edge' => ['2026-10-14', '2026-10-16', HalfDay::Start, ['2026-10-14'], 2.0];
        yield 'reversed range' => ['2026-10-16', '2026-10-12', HalfDay::None, [], 0.0];
        yield 'across new year' => ['2026-12-28', '2027-01-03', HalfDay::None, ['2027-01-01'], 4.0];
    }

    /** @param  list<string>  $holidays */
    #[DataProvider('cases')]
    public function test_days(string $from, string $to, HalfDay $half, array $holidays, float $expected): void
    {
        $this->assertSame($expected, WorkingDayCalculator::days(CarbonImmutable::parse($from), CarbonImmutable::parse($to), $half, $holidays));
    }

    public function test_working_dates_list(): void
    {
        $this->assertSame(
            ['2026-10-16', '2026-10-19'],
            WorkingDayCalculator::workingDates(CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-20'), ['2026-10-20']),
        );
    }

    public function test_a_span_over_the_limit_throws_instead_of_truncating(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkingDayCalculator::days(CarbonImmutable::parse('2000-01-01'), CarbonImmutable::parse('2100-01-01'), HalfDay::None, []);
    }
}
