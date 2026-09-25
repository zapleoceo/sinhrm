<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Modules\Scripts\DTO\ApplicationActivity;
use App\Modules\Scripts\Enums\FollowupCondition;
use App\Modules\Scripts\Support\FollowupRules;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class FollowupRulesTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        $this->now = Carbon::parse('2026-09-10 12:00:00');
    }

    public function test_no_reply(): void
    {
        $sent = $this->activity(out: '2026-09-08 10:00');
        $this->assertSame('2026-09-09 10:00', FollowupRules::dueAt(FollowupCondition::NoReply, 1, $sent, $this->now)?->format('Y-m-d H:i'));
        $this->assertNull(FollowupRules::dueAt(FollowupCondition::NoReply, 3, $sent, $this->now), 'delay not over yet');
        $this->assertNull(FollowupRules::dueAt(FollowupCondition::NoReply, 1, $this->activity(out: '2026-09-08 10:00', in: '2026-09-08 11:00'), $this->now));
        $this->assertNotNull(FollowupRules::dueAt(FollowupCondition::NoReply, 1, $this->activity(out: '2026-09-08 10:00', in: '2026-09-07 11:00'), $this->now));
        $this->assertNull(FollowupRules::dueAt(FollowupCondition::NoReply, 0, $this->activity(), $this->now), 'nothing sent');
    }

    public function test_link_not_completed_uses_stage_changes_not_stage_names(): void
    {
        $this->assertNotNull(FollowupRules::dueAt(FollowupCondition::LinkNotCompleted, 2, $this->activity(out: '2026-09-07 10:00', stage: '2026-09-01 10:00'), $this->now));
        $this->assertNull(FollowupRules::dueAt(FollowupCondition::LinkNotCompleted, 2, $this->activity(out: '2026-09-07 10:00', stage: '2026-09-08 10:00'), $this->now));
        // An answer from the candidate does not matter here: only the move of the application does.
        $this->assertNotNull(FollowupRules::dueAt(FollowupCondition::LinkNotCompleted, 2, $this->activity(out: '2026-09-07 10:00', in: '2026-09-09 10:00'), $this->now));
    }

    public function test_gone_silent_falls_back_to_creation(): void
    {
        $this->assertSame('2026-09-04 09:00', FollowupRules::dueAt(FollowupCondition::GoneSilent, 3, $this->activity(), $this->now)?->format('Y-m-d H:i'));
        $this->assertNull(FollowupRules::dueAt(FollowupCondition::GoneSilent, 3, $this->activity(touch: '2026-09-09 10:00'), $this->now));
        $this->assertNotNull(FollowupRules::dueAt(FollowupCondition::GoneSilent, 3, $this->activity(touch: '2026-09-06 10:00'), $this->now));
    }

    private function activity(?string $out = null, ?string $in = null, ?string $stage = null, ?string $touch = null): ApplicationActivity
    {
        $d = static fn (?string $v): ?Carbon => $v === null ? null : Carbon::parse($v);

        return new ApplicationActivity(1, 2, 3, Carbon::parse('2026-09-01 09:00'), $d($touch), $d($out), $d($in), $d($stage));
    }
}
