<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\NavBadgeAssertions;
use Tests\Support\RecruitingFixtures;
use Tests\Support\ScriptFixtures;
use Tests\TestCase;

final class TasksAndFollowupsTest extends TestCase
{
    use NavBadgeAssertions, RecruitingFixtures, RefreshDatabase, ScriptFixtures;

    private const string URL = '/api/ops/jobs/run';

    private Branch $branch;

    private Vacancy $vacancy;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.secret' => 'test-secret']);
        $this->branch = Branch::factory()->create();
        $this->vacancy = $this->vacancyIn($this->branch, $this->userWith(UserRole::Recruiter, [$this->branch]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ops_endpoint_requires_the_secret(): void
    {
        $this->postJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, [], ['X-Ops-Secret' => 'wrong'])->assertUnauthorized();
        config(['ops.secret' => null]);
        $this->postJson(self::URL, [], ['X-Ops-Secret' => 'test-secret'])->assertNotFound();
        $this->assertSame(0, Task::query()->count());
    }

    public function test_follow_ups_are_created_once_per_rule_and_application(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        $this->publishedScript(ScriptChannel::Chat);
        // A: we wrote 2 days ago, no answer → no_reply (delay 1) due; also silent? last touch 2 days ago < 3 → no.
        $a = $this->applied($this->vacancy, ['phone' => '+380670000010'], Carbon::parse('2026-09-01 09:00'));
        $this->ingest(Channel::Telegram, '+380670000010', ['direction' => Direction::Out, 'at' => Carbon::parse('2026-09-08 10:00')]);
        // B: we wrote, the candidate answered → no follow-up by no_reply; last touch yesterday → not silent.
        $b = $this->applied($this->vacancy, ['phone' => '+380670000011'], Carbon::parse('2026-09-01 09:00'));
        $this->ingest(Channel::Telegram, '+380670000011', ['direction' => Direction::Out, 'at' => Carbon::parse('2026-09-08 10:00')]);
        $this->ingest(Channel::Telegram, '+380670000011', ['direction' => Direction::In, 'at' => Carbon::parse('2026-09-09 10:00')]);
        // C: nobody touched it since creation 9 days ago → gone_silent (delay 3) due.
        $c = $this->applied($this->vacancy, ['phone' => '+380670000012'], Carbon::parse('2026-09-01 09:00'));
        Carbon::setTestNow('2026-09-10 12:00:00');

        $this->postJson(self::URL, [], ['X-Ops-Secret' => 'test-secret'])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('jobs.followups.ok', true)
            ->assertJsonPath('jobs.followups.rules', 2)
            ->assertJsonPath('jobs.followups.created', 2);

        $this->assertSame(['f1'], $this->ruleIds($a));
        $this->assertSame([], $this->ruleIds($b));
        $this->assertSame(['f2'], $this->ruleIds($c));
        $task = Task::query()->where('application_id', $a->id)->firstOrFail();
        $this->assertSame('Reminder', $task->title);
        $this->assertSame('reminder', $task->template_key);
        $this->assertSame($this->vacancy->recruiter_id, $task->assignee_id);
        $this->assertSame('2026-09-09 10:00:00', $task->due_at->format('Y-m-d H:i:s'));

        // Idempotent: the same run again (or an overlapping cron) creates nothing new.
        $this->postJson(self::URL, [], ['X-Ops-Secret' => 'test-secret'])->assertOk()->assertJsonPath('jobs.followups.created', 0);
        $this->assertSame(2, Task::query()->count());
    }

    public function test_tasks_api_scoping_filters_and_done(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');
        $mine = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $colleague = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $stranger = $this->userWith(UserRole::Recruiter, [Branch::factory()->create()]);
        $app = $this->applied($this->vacancy);
        $overdue = $this->task($mine->id, $app, '2026-09-08 10:00');
        $today = $this->task($mine->id, $app, '2026-09-10 18:00', 'b');
        $later = $this->task($mine->id, $app, '2026-09-12 10:00', 'c');
        $theirs = $this->task($colleague->id, $app, '2026-09-10 09:00', 'd');

        $this->getJson('/api/tasks')->assertUnauthorized();
        $this->actingAs($mine)->getJson('/api/tasks?mine=1')->assertOk()
            ->assertJsonPath('data.*.id', [$overdue->id, $today->id, $later->id])
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.0.vacancy.id', $this->vacancy->id);
        $this->actingAs($mine)->getJson('/api/tasks?mine=1&due=today')->assertOk()->assertJsonPath('data.*.id', [$overdue->id, $today->id]);
        $this->actingAs($mine)->getJson('/api/tasks?mine=1&due=overdue')->assertOk()->assertJsonPath('data.*.id', [$overdue->id]);
        $this->actingAs($mine)->getJson('/api/tasks?due=someday')->assertUnprocessable();
        // Same branch: colleagues' tasks are visible without mine=1; another branch sees nothing.
        $this->actingAs($mine)->getJson('/api/tasks')->assertOk()->assertJsonCount(4, 'data');
        $this->actingAs($mine)->getJson("/api/tasks?candidate_id={$app->candidate_id}")->assertOk()->assertJsonCount(4, 'data');
        $this->actingAs($stranger)->getJson('/api/tasks')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger)->getJson("/api/tasks?candidate_id={$app->candidate_id}")->assertForbidden();

        $this->actingAs($mine)->patchJson("/api/tasks/{$theirs->id}", ['done' => '1'])->assertOk()->assertJsonPath('data.done_at', '2026-09-10T12:00:00+00:00');
        $this->actingAs($mine)->patchJson("/api/tasks/{$theirs->id}", ['done' => false])->assertOk()->assertJsonPath('data.done_at', null);
        $this->actingAs($this->userWith(UserRole::Viewer, [$this->branch]))->patchJson("/api/tasks/{$today->id}", ['done' => true])->assertForbidden();
        $this->actingAs($stranger)->patchJson("/api/tasks/{$today->id}", ['done' => true])->assertForbidden();
        $this->actingAs($mine)->patchJson("/api/tasks/{$today->id}", [])->assertUnprocessable();

        $this->actingAs($mine)->patchJson("/api/tasks/{$today->id}", ['done' => true])->assertOk();
        $this->actingAs($mine)->getJson('/api/tasks?mine=1')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($mine)->getJson('/api/tasks?mine=1&done=1')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.id', $today->id);
    }

    public function test_nav_badge_counts_my_open_tasks_like_the_page(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');
        $mine = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $colleague = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $app = $this->applied($this->vacancy);
        $this->task($mine->id, $app, '2026-09-08 10:00');
        $done = $this->task($mine->id, $app, '2026-09-10 18:00', 'b');
        $this->task($colleague->id, $app, '2026-09-10 09:00', 'c');
        $done->forceFill(['done_at' => Carbon::now()])->save();

        // Only my own, not done: the colleague's task in the same branch does not count.
        $this->assertBadgeMatchesList($mine, 'tasks', '/api/tasks?mine=1', 1);
        $this->assertBadgeMatchesList($colleague, 'tasks', '/api/tasks?mine=1', 1);
        $this->assertBadgeMatchesList($this->userWith(UserRole::Recruiter, [$this->branch]), 'tasks', '/api/tasks?mine=1', 0);
    }

    /** @return list<string> */
    private function ruleIds(Application $application): array
    {
        return Task::query()->where('application_id', $application->id)->orderBy('rule_key')->pluck('rule_key')
            ->map(static fn (string $key): string => explode(':', $key)[1])->values()->all();
    }

    private function task(int $assigneeId, Application $application, string $due, string $rule = 'a'): Task
    {
        return Task::query()->create([
            'assignee_id' => $assigneeId,
            'candidate_id' => $application->candidate_id,
            'application_id' => $application->id,
            'type' => 'manual',
            'title' => 'Call back',
            'due_at' => Carbon::parse($due),
            'rule_key' => $rule,
        ]);
    }
}
