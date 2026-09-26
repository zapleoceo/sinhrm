<?php

declare(strict_types=1);

namespace Tests\Unit\TimeOff;

use App\Modules\TimeOff\Enums\AccrualMode;
use App\Modules\TimeOff\Support\AccrualCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class AccrualCalculatorTest extends TestCase
{
    public function test_period_keys(): void
    {
        $now = CarbonImmutable::parse('2026-10-05');

        $this->assertSame('2026', AccrualCalculator::period(AccrualMode::YearlyUpfront, $now));
        $this->assertSame('2026-10', AccrualCalculator::period(AccrualMode::Monthly, $now));
    }

    public function test_yearly_upfront_is_prorated_by_months_in_the_hire_year(): void
    {
        $now = CarbonImmutable::parse('2026-10-05');
        $yearly = static fn (string $hired): ?float => AccrualCalculator::amount(AccrualMode::YearlyUpfront, 24, CarbonImmutable::parse($hired), $now);

        $this->assertSame(24.0, $yearly('2020-06-15'));
        $this->assertSame(24.0, $yearly('2026-01-31'));
        $this->assertSame(6.0, $yearly('2026-10-20'));
        $this->assertSame(2.0, $yearly('2026-12-01'));
        $this->assertNull($yearly('2027-01-01'));
        $this->assertSame(1.67, AccrualCalculator::amount(AccrualMode::YearlyUpfront, 20, CarbonImmutable::parse('2026-12-01'), $now));
    }

    public function test_monthly_is_the_cumulative_target_minus_accrued(): void
    {
        $now = CarbonImmutable::parse('2026-10-05');

        $this->assertSame(2.0, AccrualCalculator::amount(AccrualMode::Monthly, 24, CarbonImmutable::parse('2020-01-01'), $now, 18));
        // first run of the year catches up Jan..Oct
        $this->assertSame(20.0, AccrualCalculator::amount(AccrualMode::Monthly, 24, CarbonImmutable::parse('2020-01-01'), $now));
        $this->assertSame(2.0, AccrualCalculator::amount(AccrualMode::Monthly, 24, CarbonImmutable::parse('2026-10-31'), $now));
        $this->assertNull(AccrualCalculator::amount(AccrualMode::Monthly, 24, CarbonImmutable::parse('2026-11-01'), $now));
    }

    public function test_twelve_monthly_grants_sum_exactly_to_the_annual_norm(): void
    {
        foreach ([20.0, 24.0, 17.5, 1.0] as $annual) {
            $accrued = 0.0;
            for ($month = 1; $month <= 12; $month++) {
                $grant = AccrualCalculator::amount(AccrualMode::Monthly, $annual, CarbonImmutable::parse('2020-01-01'), CarbonImmutable::create(2026, $month, 1), $accrued);
                $this->assertNotNull($grant);
                $accrued = round($accrued + $grant, 2);
            }
            $this->assertSame($annual, $accrued, "annual $annual");
        }
    }

    public function test_expiring_part(): void
    {
        $this->assertSame(0.0, AccrualCalculator::expiring(10, null));
        $this->assertSame(0.0, AccrualCalculator::expiring(5, 5));
        $this->assertSame(0.0, AccrualCalculator::expiring(-2, 0));
        $this->assertSame(7.5, AccrualCalculator::expiring(12.5, 5));
    }
}
