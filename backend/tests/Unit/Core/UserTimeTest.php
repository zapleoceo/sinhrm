<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Support\UserTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The user's day vs the UTC storage day (config app.user_timezone), in winter (+02:00) and summer (+03:00). */
final class UserTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_users_day_starts_at_local_midnight_and_goes_to_storage_in_utc(): void
    {
        config(['app.user_timezone' => 'Europe/Kyiv']);

        $winter = UserTime::now(Carbon::parse('2026-01-15 23:30:00', 'UTC'));
        $this->assertSame('2026-01-16T01:30:00+02:00', $winter->toIso8601String());
        $this->assertSame('2026-01-15 22:00:00', UserTime::toStorage($winter->copy()->startOfDay())->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-16 21:59:59', UserTime::toStorage($winter->copy()->endOfDay())->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-07-16 00:30:00');
        $summer = UserTime::now();
        $this->assertSame('2026-07-16T03:30:00+03:00', $summer->toIso8601String());
        $this->assertSame('2026-07-15 21:00:00', UserTime::toStorage($summer->copy()->startOfDay())->format('Y-m-d H:i:s'));
    }

    public function test_the_zone_comes_from_config_and_the_input_is_not_changed(): void
    {
        config(['app.user_timezone' => 'UTC']);
        $now = Carbon::parse('2026-07-15 23:30:00', 'UTC');

        $this->assertSame('UTC', UserTime::timezone());
        $this->assertSame('2026-07-15', UserTime::now($now)->toDateString());

        config(['app.user_timezone' => '']);
        $this->assertSame('Europe/Kyiv', UserTime::timezone());
        $this->assertSame('2026-07-16', UserTime::now($now)->toDateString());
        $this->assertSame('UTC', $now->getTimezone()->getName());
    }
}
