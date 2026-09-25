<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\DTO\MoveData;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class TimelineApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Branch $branch;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->application = $this->applied(
            $this->vacancyIn($this->branch),
            ['phone' => '+380671112233', 'email' => 'tl@example.test'],
            Carbon::parse('2026-09-01 09:00:00'),
        );
    }

    public function test_timeline_merges_touchpoints_and_stage_changes_newest_first(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $this->ingest(Channel::Call, '0671112233', ['at' => Carbon::parse('2026-09-02 10:00:00')]);
        $this->app->make(ApplicationService::class)
            ->move($recruiter, $this->application, new MoveData($this->stageAt(2)->id), Carbon::parse('2026-09-03 10:00:00'));
        $this->ingest(Channel::Email, 'TL@example.test', ['at' => Carbon::parse('2026-09-04 10:00:00'), 'via_product' => true]);

        $response = $this->actingAs($recruiter)->getJson("/api/candidates/{$this->application->candidate_id}/timeline")->assertOk();
        // 2 touches + 2 stage changes (creation + move); system touchpoints of stage changes are not duplicated.
        $response->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.type', 'touchpoint')
            ->assertJsonPath('data.0.touchpoint.channel', 'email')
            ->assertJsonPath('data.0.touchpoint.via_product', true)
            ->assertJsonPath('data.1.type', 'stage_change')
            ->assertJsonPath('data.1.stage_change.to_stage.id', $this->stageAt(2)->id)
            ->assertJsonPath('data.1.stage_change.from_stage.id', $this->stageAt(1)->id)
            ->assertJsonPath('data.2.touchpoint.channel', 'call')
            ->assertJsonPath('data.2.touchpoint.via_product', false)
            ->assertJsonPath('data.3.type', 'stage_change')
            ->assertJsonPath('data.3.stage_change.from_stage', null);
        $ats = array_column($response->json('data'), 'at');
        $sorted = $ats;
        rsort($sorted);
        $this->assertSame($sorted, $ats);
    }

    public function test_channel_filter_and_pagination(): void
    {
        $viewer = $this->userWith(UserRole::Viewer, [$this->branch]);
        foreach (range(1, 3) as $i) {
            $this->ingest(Channel::Telegram, '+380671112233', ['at' => Carbon::parse("2026-09-0{$i} 12:00:00")]);
        }
        $this->ingest(Channel::Viber, '+380671112233');
        $url = "/api/candidates/{$this->application->candidate_id}/timeline";

        $this->actingAs($viewer)->getJson("$url?channel=telegram")->assertOk()->assertJsonPath('meta.total', 3);
        $this->actingAs($viewer)->getJson("$url?channel=telegram,viber&perPage=2")->assertOk()
            ->assertJsonPath('meta.total', 4)->assertJsonCount(2, 'data');
        $this->actingAs($viewer)->getJson("$url?channel[]=stage")->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.type', 'stage_change');
        $this->actingAs($viewer)->getJson("$url?channel=fax")->assertUnprocessable();
        $this->actingAs($this->userWith(UserRole::Viewer, [Branch::factory()->create()]))->getJson($url)->assertForbidden();
    }

    public function test_log_touchpoint_manually_updates_last_touch(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $url = "/api/candidates/{$this->application->candidate_id}/touchpoints";

        $this->actingAs($recruiter)->postJson($url, ['channel' => 'note'])->assertUnprocessable();
        $this->actingAs($recruiter)->postJson($url, ['channel' => 'system', 'body' => 'x'])->assertUnprocessable();
        $this->actingAs($recruiter)->postJson($url, ['channel' => 'call', 'duration_sec' => '125', 'occurred_at' => '2026-09-05T10:00:00+00:00'])
            ->assertCreated()
            ->assertJsonPath('data.via_product', true)
            ->assertJsonPath('data.meta.duration_sec', 125)
            ->assertJsonPath('data.application_id', $this->application->id)
            ->assertJsonPath('data.author.id', $recruiter->id);
        $this->assertSame('2026-09-05 10:00:00', $this->application->fresh()?->last_touch_at?->format('Y-m-d H:i:s'));

        // An older touch never moves last_touch_at back.
        $this->actingAs($recruiter)->postJson($url, ['channel' => 'note', 'body' => 'Old', 'occurred_at' => '2026-09-02T10:00:00+00:00'])->assertCreated();
        $this->assertSame('2026-09-05 10:00:00', $this->application->fresh()?->last_touch_at?->format('Y-m-d H:i:s'));

        $other = $this->applied($this->vacancyIn($this->branch));
        $this->actingAs($recruiter)->postJson($url, ['channel' => 'meeting', 'application_id' => $other->id])
            ->assertUnprocessable()->assertJsonPath('code', 'application_mismatch');
        $this->actingAs($this->userWith(UserRole::Viewer, [$this->branch]))
            ->postJson($url, ['channel' => 'note', 'body' => 'x'])->assertForbidden();
    }
}
