<?php

declare(strict_types=1);

namespace Tests\Feature\Pulse;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Models\Employee;
use App\Modules\Pulse\Models\MoodCheckin;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\Support\PulseFixtures;
use Tests\TestCase;

/** Mood check-ins: own history only, team trends with a minimum group, manager alerts (once per week). */
final class MoodTest extends TestCase
{
    use PeopleFixtures, PulseFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00'); // a Wednesday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<Employee>  $people */
    private function moods(array $people, string $day, int $score): void
    {
        foreach ($people as $p) {
            MoodCheckin::query()->create(['employee_id' => $p->id, 'day' => $day, 'score' => $score, 'comment' => null]);
        }
    }

    public function test_check_in_and_personal_history(): void
    {
        ['worker' => $worker, 'lead' => $lead] = $this->org();
        $user = $this->userOf($worker);

        $this->actingAs($user)->getJson('/api/pulse/mood/today')->assertOk()
            ->assertJsonPath('data.ask', true)->assertJsonPath('data.today', null)->assertJsonPath('data.question', 'Як ваш настрій сьогодні?');
        $this->actingAs($user)->postJson('/api/pulse/mood', ['score' => 2, 'comment' => 'Tired'])->assertCreated()->assertJsonPath('data.score', 2);
        $this->actingAs($user)->postJson('/api/pulse/mood', ['score' => 4])->assertCreated();
        $this->assertSame(1, MoodCheckin::query()->count(), 'one check-in per day, the last answer wins');
        $this->actingAs($user)->getJson('/api/pulse/mood/today')->assertOk()->assertJsonPath('data.ask', false)->assertJsonPath('data.today.score', 4);
        $this->actingAs($user)->getJson('/api/pulse/mood/me')->assertOk()->assertJsonCount(1, 'data');
        // Nobody else gets personal entries: the manager's own history is empty, the API has no per-person endpoint.
        $this->actingAs($this->userOf($lead))->getJson('/api/pulse/mood/me')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->postJson('/api/pulse/mood', ['score' => 6])->assertUnprocessable();

        // Weekday off: not asked (answering is still allowed).
        $this->actingAs($this->login(UserRole::Admin))->putJson('/api/pulse/mood/settings', [
            'weekdays' => [1, 2], 'question' => 'How are you?', 'required' => true, 'alert_drop' => 0.5, 'min_group' => 5,
        ])->assertOk()->assertJsonPath('data.weekdays', [1, 2]);
        $this->actingAs($this->userOf($lead))->getJson('/api/pulse/mood/today')->assertOk()->assertJsonPath('data.ask', false)->assertJsonPath('data.required', true);
        $this->actingAs($user)->putJson('/api/pulse/mood/settings', ['weekdays' => [], 'question' => 'x', 'required' => false, 'alert_drop' => 1, 'min_group' => 5])->assertForbidden();
        $this->actingAs($this->login(UserRole::Admin))->putJson('/api/pulse/mood/settings', ['weekdays' => [1], 'question' => 'x', 'required' => false, 'alert_drop' => 1, 'min_group' => 2])
            ->assertUnprocessable();
    }

    public function test_team_trend_is_suppressed_below_the_minimum_group(): void
    {
        ['lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org();
        $this->moods([$worker, $peer], '2026-10-06', 1);

        $small = $this->actingAs($this->userOf($lead))->getJson('/api/pulse/mood/team?weeks=2')->assertOk()
            ->assertJsonPath('data.team_size', null)->assertJsonPath('data.coverage', null)->json('data.weeks');
        $this->assertTrue($small[1]['suppressed']);
        $this->assertNull($small[1]['average']);

        $more = $this->people(3, ['manager_id' => $lead->id]);
        $this->moods($more, '2026-10-06', 5);
        MoodCheckin::query()->where('employee_id', $worker->id)->update(['comment' => 'Too many meetings']);
        $data = $this->actingAs($this->userOf($lead))->getJson('/api/pulse/mood/team?weeks=2')->assertOk()->json('data');
        $this->assertSame(5, $data['team_size']);
        $this->assertEquals(['answered' => 5, 'total' => 5], $data['coverage']);
        $this->assertEquals(['week_start' => '2026-10-05', 'respondents' => 5, 'average' => 3.4, 'distribution' => [1 => 2, 2 => 0, 3 => 0, 4 => 0, 5 => 3], 'suppressed' => false], $data['weeks'][1]);
        $this->assertSame(['Too many meetings'], $data['comments']);
        $this->assertStringNotContainsString($worker->full_name, (string) json_encode($data));

        // Employees without reports have no team view; admins see everyone.
        $this->actingAs($this->userOf($worker))->getJson('/api/pulse/mood/team')->assertForbidden();
        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/pulse/mood/team?weeks=1')->assertOk()->assertJsonPath('data.weeks.0.respondents', 5);
    }

    public function test_manager_alert_when_team_mood_drops(): void
    {
        ['head' => $head, 'lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org();
        $team = [$worker, $peer, ...$this->people(3, ['manager_id' => $lead->id])];
        $this->moods($team, '2026-09-28', 5); // previous 7 days
        $this->moods($team, '2026-10-06', 3); // last 7 days

        $this->pulseTick();
        $this->pulseTick();
        // lead and head (whose subtree holds the same people) get one alert each, however often cron runs.
        $tasks = Task::query()->where('type', 'mood_alert')->get();
        $this->assertCount(2, $tasks, 'one alert per manager per week');
        $this->assertEqualsCanonicalizing([$this->userOf($lead)->id, $this->userOf($head)->id], $tasks->pluck('assignee_id')->all());
        $task = $tasks->firstWhere('assignee_id', $this->userOf($lead)->id);
        $this->assertNotNull($task);
        $this->assertSame($this->userOf($lead)->id, $task->assignee_id);
        $this->assertSame('mood:2026-W41', $task->rule_key);
        $this->assertStringNotContainsString($worker->full_name, $task->title);
        $this->assertSame(0, Task::query()->where('type', 'mood_alert')->where('assignee_id', $this->userOf($worker)->id)->count());
        $this->actingAs($this->userOf($lead))->getJson('/api/tasks?source=pulse')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_no_alert_for_small_teams_or_small_drops(): void
    {
        ['lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org();
        $this->moods([$worker, $peer], '2026-09-28', 5);
        $this->moods([$worker, $peer], '2026-10-06', 1);
        $this->assertSame(0, $this->pulseTick()['mood_alerts']);

        $more = $this->people(3, ['manager_id' => $lead->id]);
        $this->moods($more, '2026-09-28', 4);
        $this->moods($more, '2026-10-06', 4);
        MoodCheckin::query()->whereDate('day', '2026-10-06')->update(['score' => 4]);
        MoodCheckin::query()->whereDate('day', '2026-09-28')->update(['score' => 4]);
        $this->assertSame(0, $this->pulseTick()['mood_alerts']);
        $this->assertSame(0, Task::query()->where('type', 'mood_alert')->count());
    }
}
