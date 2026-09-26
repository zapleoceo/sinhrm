<?php

declare(strict_types=1);

namespace Tests\Unit\TimeOff;

use App\Modules\TimeOff\Enums\HalfDay;
use App\Modules\TimeOff\Support\WorkingDayCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
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

    /** @return iterable<string, array{string, int, list<string>, string}> */
    public static function addCases(): iterable
    {
        yield 'monday + 2' => ['2026-10-12 09:00:00', 2, [], '2026-10-14 09:00:00'];
        yield 'friday + 2 → tuesday' => ['2026-10-16 10:00:00', 2, [], '2026-10-20 10:00:00'];
        yield 'friday + 1 → monday' => ['2026-10-16 17:30:00', 1, [], '2026-10-19 17:30:00'];
        yield 'saturday + 1 → monday' => ['2026-10-17 10:00:00', 1, [], '2026-10-19 10:00:00'];
        yield 'sunday + 2 → tuesday' => ['2026-10-18 10:00:00', 2, [], '2026-10-20 10:00:00'];
        yield 'friday + 2 with monday holiday → wednesday' => ['2026-10-16 10:00:00', 2, ['2026-10-19'], '2026-10-21 10:00:00'];
        yield 'holiday on a weekend changes nothing' => ['2026-10-16 10:00:00', 2, ['2026-10-17'], '2026-10-20 10:00:00'];
        yield 'start day being a holiday is not counted' => ['2026-10-14 10:00:00', 1, ['2026-10-14'], '2026-10-15 10:00:00'];
        yield 'across new year' => ['2026-12-31 12:00:00', 2, ['2027-01-01'], '2027-01-05 12:00:00'];
        yield 'zero days' => ['2026-10-17 10:00:00', 0, [], '2026-10-17 10:00:00'];
    }

    /** @param  list<string>  $holidays */
    #[DataProvider('addCases')]
    public function test_add_working_days(string $from, int $days, array $holidays, string $expected): void
    {
        $this->assertSame($expected, WorkingDayCalculator::addWorkingDays(Carbon::parse($from), $days, $holidays)->toDateTimeString());
    }

    public function test_add_working_days_does_not_mutate_the_start(): void
    {
        $from = Carbon::parse('2026-10-16 10:00:00');
        WorkingDayCalculator::addWorkingDays($from, 2, []);
        $this->assertSame('2026-10-16 10:00:00', $from->toDateTimeString());
    }
}
