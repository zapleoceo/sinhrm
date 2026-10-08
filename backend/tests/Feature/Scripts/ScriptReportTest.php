<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Scripts\Enums\ScriptChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RecruitingFixtures;
use Tests\Support\ScriptFixtures;
use Tests\TestCase;

final class ScriptReportTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase, ScriptFixtures;

    public function test_average_score_next_step_share_and_step_miss_rate_within_scope(): void
    {
        $this->getJson('/api/reports/scripts')->assertUnauthorized();
        $this->publishedScript(ScriptChannel::Call);
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
        $foreign = $this->userWith(UserRole::Recruiter, [$other]);
        $mine = $this->applied($this->vacancyIn($branch));
        $theirs = $this->applied($this->vacancyIn($other));

        $good = $this->goodTranscript();                  // 80, next step fixed, misses "Interest"
        $weak = 'Hello there. Please think about it.';      // 20, not fixed, misses Interest/Offer/Next step
        foreach ([$good, $weak] as $text) {
            $this->actingAs($recruiter)->postJson("/api/candidates/{$mine->candidate_id}/touchpoints", ['channel' => 'call', 'body' => $text])->assertCreated();
        }
        $this->actingAs($foreign)->postJson("/api/candidates/{$theirs->candidate_id}/touchpoints", ['channel' => 'call', 'body' => $good])->assertCreated();

        $report = $this->actingAs($recruiter)->getJson('/api/reports/scripts')->assertOk()
            ->assertJsonPath('data.totals.evaluations', 2)
            ->assertJsonPath('data.totals.avg_score', 50)
            ->assertJsonPath('data.totals.next_step_fixed_pct', 50)
            ->assertJsonPath('data.recruiters.0.author_id', $recruiter->id)
            ->assertJsonPath('data.recruiters.0.avg_score', 50)
            ->json('data.steps');
        $byTitle = array_column($report, null, 'title');
        $this->assertSame(100.0, (float) $byTitle['Interest']['miss_rate_pct']);
        $this->assertSame(0.0, (float) $byTitle['Greeting']['miss_rate_pct']);
        $this->assertSame(50.0, (float) $byTitle['Offer']['miss_rate_pct']);
        $this->assertSame('Interest', $report[0]['title']);

        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/reports/scripts')->assertOk()->assertJsonPath('data.totals.evaluations', 3);
        $this->actingAs($recruiter)->getJson('/api/reports/scripts?from=2020-01-01&to=2020-01-31')->assertOk()->assertJsonPath('data.totals.evaluations', 0);
        $this->actingAs($recruiter)->getJson('/api/reports/scripts?from=bad')->assertUnprocessable();
    }

    /** A call at 00:30 Kyiv (21:30 UTC of the day before) belongs to the Kyiv day of the report range. */
    public function test_range_days_are_kyiv_days(): void
    {
        try {
            Carbon::setTestNow('2026-10-11 21:30:00'); // 2026-10-12 00:30 Kyiv
            $this->publishedScript(ScriptChannel::Call);
            $branch = Branch::factory()->create();
            $recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
            $mine = $this->applied($this->vacancyIn($branch));
            $this->actingAs($recruiter)->postJson("/api/candidates/{$mine->candidate_id}/touchpoints", ['channel' => 'call', 'body' => $this->goodTranscript()])->assertCreated();

            $this->actingAs($recruiter)->getJson('/api/reports/scripts?from=2026-10-12&to=2026-10-12')->assertOk()->assertJsonPath('data.totals.evaluations', 1);
            $this->actingAs($recruiter)->getJson('/api/reports/scripts?from=2026-10-11&to=2026-10-11')->assertOk()->assertJsonPath('data.totals.evaluations', 0);
            $this->actingAs($recruiter)->getJson('/api/reports/scripts')->assertOk()->assertJsonPath('data.range.to', '2026-10-12');
        } finally {
            Carbon::setTestNow();
        }
    }
}
