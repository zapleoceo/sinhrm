<?php

declare(strict_types=1);

namespace Tests\Unit\Desk;

use App\Modules\Desk\Support\Sla;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class SlaTest extends TestCase
{
    public function test_due_times_and_breaches(): void
    {
        $opened = Carbon::parse('2026-10-05 09:00:00');

        $open = Sla::of($opened, 4, 24, null, null, Carbon::parse('2026-10-05 12:59:59'));
        $this->assertSame('2026-10-05 13:00:00', $open['first_response_due']?->toDateTimeString());
        $this->assertSame('2026-10-06 09:00:00', $open['resolve_due']?->toDateTimeString());
        $this->assertFalse($open['first_response_breached']);

        $late = Sla::of($opened, 4, 24, null, null, Carbon::parse('2026-10-05 13:00:01'));
        $this->assertTrue($late['first_response_breached'], 'no answer and the due time passed');

        $answeredLate = Sla::of($opened, 4, 24, Carbon::parse('2026-10-05 14:00:00'), null, Carbon::parse('2026-10-05 14:30:00'));
        $this->assertTrue($answeredLate['first_response_breached'], 'answered after the due time stays breached');

        $answeredInTime = Sla::of($opened, 4, 24, Carbon::parse('2026-10-05 10:00:00'), Carbon::parse('2026-10-05 20:00:00'), Carbon::parse('2026-10-09 00:00:00'));
        $this->assertFalse($answeredInTime['first_response_breached']);
        $this->assertFalse($answeredInTime['resolve_breached'], 'resolving stops the clock');

        $none = Sla::of($opened, null, null, null, null, Carbon::parse('2027-01-01'));
        $this->assertNull($none['first_response_due']);
        $this->assertFalse($none['first_response_breached'] || $none['resolve_breached']);
    }
}
