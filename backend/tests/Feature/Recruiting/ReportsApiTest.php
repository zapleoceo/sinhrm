<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\DTO\MoveData;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Models\RejectReason;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class ReportsApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Branch $north;

    private Branch $south;

    private User $recruiter;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 12:00:00');
        [$this->north, $this->south] = Branch::factory()->count(2)->create()->all();
        $this->recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);

        $vacancy = $this->vacancyIn($this->north);
        $a = $this->applied($vacancy, ['phone' => '+380671000001', 'source' => 'work_ua']);
        $b = $this->applied($vacancy, ['phone' => '+380671000002', 'source' => 'work_ua']);
        $this->applied($vacancy, ['phone' => '+380671000003', 'source' => 'referral']);
        $this->applied($this->vacancyIn($this->south), ['phone' => '+380671000004', 'source' => 'site']);

        $moves = $this->app->make(ApplicationService::class);
        $reason = RejectReason::query()->orderBy('id')->firstOrFail();
        $moves->move($this->recruiter, $a, new MoveData($this->rejectStage()->id, null, $reason->id));
        $moves->move($this->recruiter, $b, new MoveData($this->hireStage()->id));

        $this->ingest(Channel::Call, '+380671000001', ['author_id' => $this->recruiter->id, 'via_product' => true]);
        $this->ingest(Channel::Call, '+380671000002', ['author_id' => $this->recruiter->id]);
        $this->ingest(Channel::Telegram, '+380671000003', ['author_id' => $this->recruiter->id]);
        $this->ingest(Channel::Viber, '+380671000004'); // south, no author
        $this->ingest(Channel::Call, '+380671000001', ['author_id' => $this->recruiter->id, 'at' => Carbon::parse('2026-01-01')]); // out of range
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_touches_by_recruiter_and_channel(): void
    {
        $this->actingAs($this->recruiter)->getJson('/api/reports/touches?from=2026-09-01&to=2026-09-30')->assertOk()
            ->assertJsonPath('data.range', ['from' => '2026-09-01', 'to' => '2026-09-30'])
            ->assertJsonPath('data.totals', ['total' => 3, 'via_product' => 1, 'captured' => 2])
            ->assertJsonCount(3, 'data.rows');
        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/reports/touches')->assertOk()
            ->assertJsonPath('data.totals.total', 4);
        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/reports/touches?from=2025-12-31&to=2026-01-02')->assertOk()
            ->assertJsonPath('data.totals.total', 1);
    }

    public function test_funnel_sources_and_reject_reasons(): void
    {
        $funnel = $this->actingAs($this->recruiter)->getJson('/api/reports/funnel')->assertOk()
            ->assertJsonPath('data.totals.total', 3)->json('data.rows');
        $byStage = array_column($funnel, 'count', 'stage_name');
        $this->assertSame(1, $byStage[$this->stageAt(1)->name]);
        $this->assertSame(1, $byStage[$this->rejectStage()->name]);

        $this->actingAs($this->recruiter)->getJson('/api/reports/sources')->assertOk()
            ->assertJsonPath('data.totals', ['candidates' => 3, 'hired' => 1])
            ->assertJsonPath('data.rows.0', ['source' => 'referral', 'candidates' => 1, 'hired' => 0])
            ->assertJsonPath('data.rows.1', ['source' => 'work_ua', 'candidates' => 2, 'hired' => 1]);

        $this->actingAs($this->recruiter)->getJson('/api/reports/reject-reasons')->assertOk()
            ->assertJsonPath('data.totals.total', 1)
            ->assertJsonPath('data.rows.0.count', 1);
        $this->actingAs($this->userWith(UserRole::Viewer, [$this->south]))->getJson('/api/reports/reject-reasons')->assertOk()
            ->assertJsonPath('data.totals.total', 0);
    }

    public function test_date_params_are_validated(): void
    {
        $this->getJson('/api/reports/funnel')->assertUnauthorized();
        foreach (['from=2026-13-01', 'from=yesterday', 'from=2026-09-10&to=2026-09-01', 'vacancy_id=abc'] as $q) {
            $this->actingAs($this->recruiter)->getJson("/api/reports/funnel?$q")->assertUnprocessable();
        }
    }
}
