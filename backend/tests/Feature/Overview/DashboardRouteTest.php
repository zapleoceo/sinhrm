<?php

declare(strict_types=1);

namespace Tests\Feature\Overview;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\DTO\MoveData;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\RejectReason;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Services\ApplicationService;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/**
 * GET /api/dashboard: "Маршрут дня" (day_route) and the funnel captions (funnel_insights) — real data only and the
 * same visibility rules as the rest of the dashboard. Synthetic data.
 */
final class DashboardRouteTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_day_route_lists_my_interviews_and_tasks_of_today_only(): void
    {
        $mine = Branch::factory()->create();
        $other = Branch::factory()->create();
        $recruiter = $this->userWith(UserRole::Recruiter, [$mine]);
        $colleague = $this->userWith(UserRole::Recruiter, [$other]);
        $interviewer = $this->userWith(UserRole::Employee);

        Carbon::setTestNow('2026-09-30 08:00:00');
        $visible = $this->applied($this->vacancyIn($mine), ['full_name' => 'Route Visible [TEST]', 'phone' => '+380670000301']);
        $foreign = $this->applied($this->vacancyIn($other), ['full_name' => 'Route Foreign [TEST]', 'phone' => '+380670000302']);
        DB::table('application_interviewers')->insert(['application_id' => $foreign->id, 'user_id' => $interviewer->id, 'created_at' => Carbon::now()]);

        // 14:30 Kyiv = 11:30 UTC today → on the line, normalised to the server time zone.
        $this->meeting($recruiter, $visible, '2026-09-30T14:30:00+03:00', ['title' => 'Interview [TEST]', 'meeting_type' => 'online', 'end' => '2026-09-30T15:30:00+03:00']);
        $this->meeting($recruiter, $visible, '2026-10-01T10:00:00+00:00');                 // tomorrow
        $this->meeting($recruiter, $visible, '2026-09-30T01:30:00+03:00');                 // = yesterday 22:30 UTC
        $this->meeting($recruiter, $visible, null);                                        // no start: not a scheduled meeting
        $this->meeting($recruiter, $foreign, '2026-09-30T12:00:00+00:00');                 // mine, but the candidate is not visible to me
        $this->meeting($colleague, $foreign, '2026-09-30T13:00:00+00:00', ['title' => 'Panel [TEST]']); // someone else's
        Task::query()->create(['assignee_id' => $recruiter->id, 'candidate_id' => $visible->candidate_id, 'application_id' => $visible->id,
            'type' => 'manual', 'title' => 'Send the test task', 'due_at' => Carbon::parse('2026-09-30 15:00')]);
        Task::query()->create(['assignee_id' => $recruiter->id, 'type' => 'manual', 'title' => 'Overdue one', 'due_at' => Carbon::parse('2026-09-29 09:00')]);
        Task::query()->create(['assignee_id' => $colleague->id, 'type' => 'manual', 'title' => 'Not mine', 'due_at' => Carbon::parse('2026-09-30 10:00')]);
        Carbon::setTestNow('2026-09-30 12:00:00');

        $this->actingAs($recruiter)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.my_tasks.total', 2)
            ->assertJsonPath('data.day_route.date', '2026-09-30')
            ->assertJsonPath('data.day_route.interviews', 1)
            ->assertJsonPath('data.day_route.tasks', 1)
            ->assertJsonCount(2, 'data.day_route.items')
            ->assertJsonPath('data.day_route.items.0.kind', 'interview')
            ->assertJsonPath('data.day_route.items.0.at', '2026-09-30T11:30:00+00:00')
            ->assertJsonPath('data.day_route.items.0.end', '2026-09-30T12:30:00+00:00')
            ->assertJsonPath('data.day_route.items.0.title', 'Interview [TEST]')
            ->assertJsonPath('data.day_route.items.0.meeting_type', 'online')
            ->assertJsonPath('data.day_route.items.0.candidate.name', 'Route Visible [TEST]')
            ->assertJsonPath('data.day_route.items.1.kind', 'task')
            ->assertJsonPath('data.day_route.items.1.title', 'Send the test task')
            ->assertJsonPath('data.day_route.items.1.at', '2026-09-30T15:00:00+00:00');

        // An interviewer (employee, no Recruiting role) sees the interview of "their" application — and nothing else.
        $this->actingAs($interviewer)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.day_route.interviews', 1)
            ->assertJsonPath('data.day_route.items.0.title', 'Panel [TEST]')
            ->assertJsonPath('data.day_route.items.0.candidate.name', 'Route Foreign [TEST]')
            ->assertJsonPath('data.counts.active', 0);

        // Admin sees everything in Recruiting, but the route is personal: only meetings they are involved in.
        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.day_route', ['date' => '2026-09-30', 'interviews' => 0, 'tasks' => 0, 'items' => []]);
    }

    public function test_funnel_insights_come_from_the_stage_history_in_my_scope(): void
    {
        $mine = Branch::factory()->create();
        $other = Branch::factory()->create();
        $recruiter = $this->userWith(UserRole::Recruiter, [$mine]);
        $admin = $this->userWith(UserRole::Admin);
        $vacancy = $this->vacancyIn($mine);

        Carbon::setTestNow('2026-09-01 10:00:00');
        /** @var list<Application> $apps */
        $apps = [];
        for ($i = 0; $i < 12; $i++) {
            $apps[] = $this->applied($vacancy, ['full_name' => "Funnel {$i} [TEST]", 'phone' => sprintf('+38067000%04d', 400 + $i)]);
        }
        // 12 → stage 2; 10 → stage 3 (2 rejected); 3 → stage 4 (7 rejected) → offer on days 10, 20, 15.
        foreach ($apps as $a) {
            $this->move($admin, $a, 2, '2026-09-02 10:00');
        }
        foreach (array_slice($apps, 0, 2) as $a) {
            $this->reject($admin, $a, '2026-09-03 10:00');
        }
        foreach (array_slice($apps, 2) as $a) {
            $this->move($admin, $a, 3, '2026-09-04 10:00');
        }
        foreach (array_slice($apps, 5) as $a) {
            $this->reject($admin, $a, '2026-09-05 10:00');
        }
        foreach ([[2, '2026-09-11 10:00'], [3, '2026-09-21 10:00'], [4, '2026-09-16 10:00']] as [$k, $offerAt]) {
            $this->move($admin, $apps[$k], 4, '2026-09-06 10:00');
            $this->move($admin, $apps[$k], 6, $offerAt);
        }
        Carbon::setTestNow('2026-09-30 12:00:00');

        $expected = [
            'period_days' => 90,
            'min_sample' => 10,
            'min_offer_observations' => 3,
            'bottleneck' => ['from' => 'Співбесіда', 'to' => 'Зустріч у філії', 'from_kind' => 'select', 'to_kind' => 'select', 'conversion' => 30, 'passed' => 3, 'decided' => 10],
            'offer_path' => ['days' => 15, 'observations' => 3],
        ];
        $this->actingAs($recruiter)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.funnel_insights', $expected);
        $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.funnel_insights', $expected);

        // Another branch's recruiter and a viewer without branches: no data in scope → no captions, not someone else's.
        foreach ([$this->userWith(UserRole::Recruiter, [$other]), $this->userWith(UserRole::Viewer)] as $outsider) {
            $this->actingAs($outsider)->getJson('/api/dashboard')->assertOk()
                ->assertJsonPath('data.funnel_insights.bottleneck', null)
                ->assertJsonPath('data.funnel_insights.offer_path', null);
        }

        // Outside the 90-day window the same history no longer counts.
        Carbon::setTestNow('2027-01-15 12:00:00');
        $this->actingAs($recruiter)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.funnel_insights.bottleneck', null)
            ->assertJsonPath('data.funnel_insights.offer_path', null);
    }

    public function test_small_samples_are_not_shown(): void
    {
        $mine = Branch::factory()->create();
        $recruiter = $this->userWith(UserRole::Recruiter, [$mine]);
        $admin = $this->userWith(UserRole::Admin);
        $vacancy = $this->vacancyIn($mine);

        Carbon::setTestNow('2026-09-01 10:00:00');
        // 9 decided at stage 1 (< 10) and 2 offers (< 3).
        for ($i = 0; $i < 9; $i++) {
            $a = $this->applied($vacancy, ['full_name' => "Small {$i} [TEST]", 'phone' => sprintf('+38067000%04d', 500 + $i)]);
            $i < 2 ? $this->move($admin, $a, 6, '2026-09-10 10:00') : $this->reject($admin, $a, '2026-09-02 10:00');
        }
        Carbon::setTestNow('2026-09-30 12:00:00');

        $this->actingAs($recruiter)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.funnel_insights.bottleneck', null)
            ->assertJsonPath('data.funnel_insights.offer_path', null)
            ->assertJsonPath('data.funnel_insights.min_sample', 10);
    }

    /** @param  array<string, string>  $meta */
    private function meeting(User $author, Application $application, ?string $start, array $meta = []): void
    {
        Touchpoint::query()->create([
            'candidate_id' => $application->candidate_id,
            'application_id' => $application->id,
            'channel' => 'meeting',
            'direction' => 'out',
            'author_id' => $author->id,
            'occurred_at' => Carbon::now(),
            'meta' => array_filter(['start' => $start, 'event_id' => 'evt-test'] + $meta, static fn (?string $v): bool => $v !== null),
            'via_product' => true,
        ]);
    }

    private function move(User $actor, Application $application, int $position, string $at): void
    {
        $this->app->make(ApplicationService::class)->move($actor, $application->refresh(), new MoveData($this->stageAt($position)->id), Carbon::parse($at));
    }

    private function reject(User $actor, Application $application, string $at): void
    {
        $reason = RejectReason::query()->where('active', true)->firstOrFail();
        $this->app->make(ApplicationService::class)
            ->move($actor, $application->refresh(), new MoveData($this->rejectStage()->id, null, $reason->id), Carbon::parse($at));
    }
}
