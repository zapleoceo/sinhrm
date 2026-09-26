<?php

declare(strict_types=1);

namespace Tests\Feature\TimeOff;

use App\Modules\Core\Contracts\WorkingCalendar;
use App\Modules\Directory\Models\Branch;
use App\Modules\TimeOff\Models\Holiday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** WorkingCalendar contract on TimeOff holidays: company-wide and per-branch. 2026-10-16 is a Friday. Synthetic data. */
final class WorkingCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekends_and_holidays_per_branch(): void
    {
        $kyiv = Branch::factory()->create(['name' => 'Synthetic A']);
        $lviv = Branch::factory()->create(['name' => 'Synthetic B']);
        Holiday::query()->create(['date' => '2026-10-19', 'name' => 'Company day', 'branch_id' => null]);
        Holiday::query()->create(['date' => '2026-10-20', 'name' => 'Branch A day', 'branch_id' => $kyiv->id]);
        $calendar = $this->app->make(WorkingCalendar::class);
        $friday = Carbon::parse('2026-10-16 10:00:00');

        // Company holiday (Mon) applies everywhere; the branch holiday (Tue) only to branch A.
        $this->assertSame('2026-10-21 10:00:00', $calendar->addWorkingDays($friday, 2)->toDateTimeString());
        $this->assertSame('2026-10-21 10:00:00', $calendar->addWorkingDays($friday, 2, $lviv->id)->toDateTimeString());
        $this->assertSame('2026-10-22 10:00:00', $calendar->addWorkingDays($friday, 2, $kyiv->id)->toDateTimeString());
        $this->assertSame('2026-10-16 10:00:00', $friday->toDateTimeString(), 'the start is not mutated');

        $this->assertTrue($calendar->isWorkingDay($friday));
        $this->assertFalse($calendar->isWorkingDay(Carbon::parse('2026-10-17')));
        $this->assertFalse($calendar->isWorkingDay(Carbon::parse('2026-10-19'), $lviv->id));
        $this->assertTrue($calendar->isWorkingDay(Carbon::parse('2026-10-20'), $lviv->id));
        $this->assertFalse($calendar->isWorkingDay(Carbon::parse('2026-10-20'), $kyiv->id));
        $this->assertSame(2, WorkingCalendar::DEFAULT_SLA_DAYS);
    }
}
