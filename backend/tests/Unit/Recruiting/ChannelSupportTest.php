<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Modules\Recruiting\Support\ChannelMath;
use App\Modules\Recruiting\Support\UtmMatcher;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** Pure tz3 helpers: UTM rule precedence and the channel report math. */
final class ChannelSupportTest extends TestCase
{
    /** @return array{id: int, channel_id: int, utm_source: string|null, utm_medium: string|null, utm_campaign: string|null, priority: int} */
    private static function rule(int $id, int $channel, ?string $source, ?string $medium = null, ?string $campaign = null, int $priority = 100): array
    {
        return ['id' => $id, 'channel_id' => $channel, 'utm_source' => $source, 'utm_medium' => $medium, 'utm_campaign' => $campaign, 'priority' => $priority];
    }

    public function test_utm_precedence(): void
    {
        $rules = [
            self::rule(1, 10, 'facebook'),
            self::rule(2, 20, 'facebook', 'paid'),
            self::rule(3, 30, 'facebook', 'paid', 'autumn'),
            self::rule(4, 40, null, 'paid', null, 50),
            self::rule(5, 50, null, 'paid', null, 50),
            self::rule(6, 60, null, null, null),
        ];
        $this->assertSame(30, UtmMatcher::match($rules, ['utm_source' => 'Facebook ', 'utm_medium' => 'PAID', 'utm_campaign' => 'autumn'])['channel_id'] ?? null);
        $this->assertSame(20, UtmMatcher::match($rules, ['source' => 'facebook', 'medium' => 'paid', 'campaign' => 'spring'])['channel_id'] ?? null);
        $this->assertSame(10, UtmMatcher::match($rules, ['utm_source' => 'facebook'])['channel_id'] ?? null);
        // Equally specific: lower priority, then older id.
        $this->assertSame(40, UtmMatcher::match($rules, ['utm_source' => 'x', 'utm_medium' => 'paid'])['channel_id'] ?? null);
        $this->assertNull(UtmMatcher::match($rules, ['utm_source' => 'google']), 'an empty rule never matches everything');
        $this->assertNull(UtmMatcher::match($rules, null));
        $this->assertSame(['utm_source' => 'a'], UtmMatcher::normalize(['source' => ' A ', 'utm_term' => 'x', 'utm_medium' => '']));
    }

    public function test_cost_proration_and_ratios(): void
    {
        $costs = [
            ['channel_id' => 1, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => 3100.0],
            ['channel_id' => 1, 'period_start' => '2026-09-21', 'period_end' => '2026-10-10', 'amount' => 200.0],
            ['channel_id' => 2, 'period_start' => '2026-12-01', 'period_end' => '2026-12-31', 'amount' => 500.0],
        ];
        // 20 of 31 days → 2000; 10 of 20 days → 100.
        $this->assertSame([1 => 2100.0], ChannelMath::prorate($costs, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-20 23:59:59')));
        $this->assertSame(33.3, ChannelMath::conversion(1, 3));
        $this->assertSame(0.0, ChannelMath::conversion(0, 0));
        $this->assertSame(1050.0, ChannelMath::costPerHire(2100.0, 2));
        $this->assertNull(ChannelMath::costPerHire(2100.0, 0));
        $this->assertNull(ChannelMath::costPerHire(null, 3));
    }
}
