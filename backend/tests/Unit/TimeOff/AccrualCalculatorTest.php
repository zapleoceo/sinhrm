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

    public function test_monthly_is_a_twelfth_from_the_hire_month(): void
    {
        $now = CarbonImmutable::parse('2026-10-05');

        $this->assertSame(2.0, AccrualCalculator::amount(AccrualMode::Monthly, 24, CarbonImmutable::parse('2020-01-01'), $now));
        $this->assertSame(2.0, AccrualCalculator::amount(AccrualMode::Monthly, 24, CarbonImmutable::parse('2026-10-31'), $now));
        $this->assertSame(1.67, AccrualCalculator::amount(AccrualMode::Monthly, 20, CarbonImmutable::parse('2026-01-01'), $now));
        $this->assertNull(AccrualCalculator::amount(AccrualMode::Monthly, 24, CarbonImmutable::parse('2026-11-01'), $now));
    }

    public function test_expiring_part(): void
    {
        $this->assertSame(0.0, AccrualCalculator::expiring(10, null));
        $this->assertSame(0.0, AccrualCalculator::expiring(5, 5));
        $this->assertSame(0.0, AccrualCalculator::expiring(-2, 0));
        $this->assertSame(7.5, AccrualCalculator::expiring(12.5, 5));
    }
}
