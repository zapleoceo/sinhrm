<?php

declare(strict_types=1);

namespace Tests\Feature\Pulse;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Department;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\ScheduledTerminationJob;
use App\Modules\Pulse\Models\Survey;
use App\Modules\Pulse\Models\SurveyResponse;
use App\Modules\Pulse\Models\SurveyWave;
use App\Modules\Pulse\Services\LifecycleSurveys;
use App\Modules\Pulse\Support\SurveyTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\PeopleFixtures;
use Tests\Support\PulseFixtures;
use Tests\TestCase;

/**
 * Exit survey vs. termination dates (owner decision 2026-10-07, B): a future termination opens the wave when it is
 * scheduled (until the end of fired_at in Kyiv, at most 14 days), cancel removes it (closes it when answered), the
 * termination itself does not add a second wave; today/past: as before, HR may answer on behalf. Only HR sees answers.
 * Summer, Kyiv = UTC+3: now is 12:00 Kyiv on 2026-07-14. Synthetic data only.
 */
final class ExitSurveyScheduleTest extends TestCase
{
    use PeopleFixtures, PulseFixtures, RefreshDatabase;

    /** @var array{head: Employee, lead: Employee, worker: Employee, peer: Employee, other: Employee} */
    private array $org;

    private Survey $exit;

    private User $hr;

    /** @var array<string, int|string> a valid answer to the five questions */
    private array $answers = ['reason' => 0, 'liked' => 'Team', 'disliked' => 'Commute', 'manager' => 4, 'return' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.user_timezone' => 'Europe/Kyiv', 'app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-14 09:00:00');
        $this->org = $this->org();
        $this->exit = $this->survey(['title' => 'Exit', 'type' => 'lifecycle', 'lifecycle_trigger' => 'exit', 'questions' => SurveyTemplates::EXIT_QUESTIONS]);
        $this->hr = $this->login(UserRole::HrManager);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_template_has_the_five_owner_questions(): void
    {
        $exit = collect(SurveyTemplates::all())->firstWhere('key', 'exit');
        $this->assertIsArray($exit);
        $this->assertSame(['reason', 'liked', 'disliked', 'manager', 'return'], array_column($exit['questions'], 'id'));
    }

    public function test_scheduled_termination_opens_the_wave_at_once_until_the_end_of_the_last_day(): void
    {
        $this->terminate($this->org['worker'], '2026-07-20');

        $wave = SurveyWave::query()->sole();
        $this->assertSame('exit:2026-07-20', $wave->trigger_key);
        $this->assertSame('open', $wave->status->value);
        $this->assertFalse($wave->anonymous);
        $this->assertSame('2026-07-14 09:00:00', $wave->starts_at->toDateTimeString());
        $this->assertSame('2026-07-20 20:59:59', $wave->ends_at->toDateTimeString(), '23:59:59 Kyiv on fired_at');
        $worker = $this->userOf($this->org['worker']);
        $this->actingAs($worker)->getJson('/api/pulse/my/waves')->assertOk()->assertJsonPath('data.0.id', $wave->id);
        $this->answer($wave, $this->org['worker'], $this->answers);

        // 00:00 Kyiv on the next day: the termination applies, the event does not create a second wave
        Carbon::setTestNow('2026-07-20 21:00:00');
        $this->app->make(ScheduledTerminationJob::class)->run(Carbon::now());
        $this->assertTrue($this->org['worker']->refresh()->isTerminated());
        $this->assertSame(1, SurveyWave::query()->count());
        $this->assertSame(1, SurveyResponse::query()->count());
    }

    public function test_a_far_date_is_capped_at_fourteen_days(): void
    {
        $this->terminate($this->org['worker'], '2026-09-01');

        $this->assertSame('2026-07-28 09:00:00', SurveyWave::query()->sole()->ends_at->toDateTimeString());
    }

    public function test_cancel_deletes_an_unanswered_wave_and_closes_an_answered_one(): void
    {
        $this->terminate($this->org['worker'], '2026-07-20');
        $this->terminate($this->org['peer'], '2026-07-21');
        $answered = SurveyWave::query()->where('subject_employee_id', $this->org['peer']->id)->sole();
        $this->answer($answered, $this->org['peer'], $this->answers);

        $this->actingAs($this->hr)->postJson('/api/people/'.$this->org['worker']->id.'/terminate/cancel')->assertOk();
        $this->actingAs($this->hr)->postJson('/api/people/'.$this->org['peer']->id.'/terminate/cancel')->assertOk();

        $this->assertSame(0, SurveyWave::query()->where('subject_employee_id', $this->org['worker']->id)->count());
        $this->assertSame('closed', $answered->refresh()->status->value);
        $this->assertSame(1, SurveyResponse::query()->where('wave_id', $answered->id)->count(), 'answers are kept');
        $this->actingAs($this->userOf($this->org['worker']))->getJson('/api/pulse/my/waves')->assertJsonCount(0, 'data');

        // scheduling again opens a fresh wave for the new date
        $this->terminate($this->org['worker'], '2026-07-25');
        $this->assertSame('exit:2026-07-25', SurveyWave::query()->where('subject_employee_id', $this->org['worker']->id)->sole()->trigger_key);

        // the same date again: the answered wave stays closed (no second wave, answers kept)
        $this->terminate($this->org['peer'], '2026-07-21');
        $this->assertSame('closed', SurveyWave::query()->where('subject_employee_id', $this->org['peer']->id)->sole()->status->value);
        $this->assertSame(1, SurveyResponse::query()->where('wave_id', $answered->id)->count());
    }

    public function test_a_closed_unanswered_wave_of_the_same_date_is_replaced_by_an_open_one(): void
    {
        $this->terminate($this->org['worker'], '2026-07-20');
        $first = SurveyWave::query()->sole();
        $this->actingAs($this->hr)->postJson("/api/pulse/waves/{$first->id}/close")->assertOk();
        $this->actingAs($this->hr)->postJson('/api/people/'.$this->org['worker']->id.'/terminate/cancel')->assertOk();
        $this->assertSame(0, SurveyWave::query()->count(), 'closed without answers is deleted on cancel');

        // a wave closed by hand (no answers) and the same date scheduled again via the service: replaced
        $this->terminate($this->org['worker'], '2026-07-20');
        $second = SurveyWave::query()->sole();
        $this->actingAs($this->hr)->postJson("/api/pulse/waves/{$second->id}/close")->assertOk();
        $this->app->make(LifecycleSurveys::class)->terminationScheduled($this->org['worker']->refresh());
        $third = SurveyWave::query()->sole();
        $this->assertNotSame($second->id, $third->id);
        $this->assertSame('open', $third->status->value);
    }

    public function test_termination_today_by_kyiv_date_works_as_before_and_hr_may_answer_on_behalf(): void
    {
        // 21:30 UTC on the 14th = 00:30 Kyiv on the 15th: fired_at 2026-07-15 is "today" → applied at once
        Carbon::setTestNow('2026-07-14 21:30:00');
        $this->terminate($this->org['worker'], '2026-07-15');
        $this->assertTrue($this->org['worker']->refresh()->isTerminated());
        $wave = SurveyWave::query()->sole();
        $this->assertSame('exit:2026-07-15', $wave->trigger_key);
        $this->assertSame('2026-07-28 21:30:00', $wave->ends_at->toDateTimeString(), '14 days, as before');

        $url = "/api/pulse/waves/{$wave->id}/responses-on-behalf";
        $this->actingAs($this->userOf($this->org['lead']))->postJson($url, ['answers' => $this->answers])->assertForbidden();
        $this->actingAs($this->hr)->postJson($url, ['answers' => ['reason' => 9]])->assertStatus(422)->assertJsonPath('code', 'invalid_answers');
        $this->actingAs($this->hr)->postJson($url, ['answers' => $this->answers])->assertCreated();
        $this->actingAs($this->hr)->postJson($url, ['answers' => $this->answers])->assertStatus(409)->assertJsonPath('code', 'already_responded');
        $response = SurveyResponse::query()->sole();
        $this->assertSame([$this->org['worker']->id, $this->hr->id], [$response->employee_id, $response->entered_by_user_id]);
        $this->actingAs($this->hr)->getJson("/api/pulse/waves/{$wave->id}/responses")->assertJsonPath('data.0.entered_by_user_id', $this->hr->id);

        // not an exit wave (team, hire_30) → 409; a closed one → 409
        $team = $this->wave($this->survey());
        $hire = $this->wave($this->survey(['type' => 'lifecycle', 'lifecycle_trigger' => 'hire_30']), [
            'anonymous' => false, 'min_group_size' => 1, 'subject_employee_id' => $this->org['peer']->id, 'trigger_key' => 'hire_30:2026-06-14',
        ]);
        foreach ([$team, $hire] as $other) {
            $this->actingAs($this->hr)->postJson("/api/pulse/waves/{$other->id}/responses-on-behalf", ['answers' => []])
                ->assertStatus(409)->assertJsonPath('code', 'not_exit_wave');
        }
        $this->actingAs($this->hr)->postJson("/api/pulse/waves/{$wave->id}/close")->assertOk();
        $this->actingAs($this->hr)->postJson($url, ['answers' => $this->answers])->assertStatus(409)->assertJsonPath('code', 'wave_not_open');
    }

    public function test_hr_cannot_answer_for_an_employee_who_still_can(): void
    {
        $this->terminate($this->org['worker'], '2026-07-20');
        $wave = SurveyWave::query()->sole();
        $url = "/api/pulse/waves/{$wave->id}/responses-on-behalf";
        $this->actingAs($this->hr)->postJson($url, ['answers' => $this->answers])->assertStatus(409)->assertJsonPath('code', 'employee_can_answer');

        // the login blocked (e.g. by hand): the person cannot answer any more, HR may
        $this->userOf($this->org['worker'])->forceFill(['status' => 'blocked'])->save();
        $this->actingAs($this->hr)->postJson($url, ['answers' => $this->answers])->assertCreated();
        $this->assertSame($this->hr->id, SurveyResponse::query()->sole()->entered_by_user_id);
    }

    public function test_only_hr_reads_exit_answers(): void
    {
        $this->terminate($this->org['worker'], '2026-07-20');
        $wave = SurveyWave::query()->sole();
        $this->answer($wave, $this->org['worker'], $this->answers);

        foreach ([$this->org['lead'], $this->org['head'], $this->org['worker'], $this->org['peer']] as $who) {
            $user = $this->userOf($who);
            $this->actingAs($user)->getJson("/api/pulse/waves/{$wave->id}/results")->assertForbidden();
            $this->actingAs($user)->getJson("/api/pulse/waves/{$wave->id}/responses")->assertForbidden();
        }
        $this->actingAs($this->hr)->getJson("/api/pulse/waves/{$wave->id}/responses")->assertOk()
            ->assertJsonPath('data.0.employee_id', $this->org['worker']->id)
            ->assertJsonPath('data.0.entered_by_user_id', null)
            ->assertJsonPath('data.0.answers.liked', 'Team');
    }

    /**
     * A manual (team) wave of the exit survey makes the manager a reader of that survey; "compare with the exit wave"
     * then prints the leaving person's own answers as the previous value. Lifecycle answers never leave HR.
     */
    public function test_a_manager_cannot_read_exit_answers_through_a_comparison(): void
    {
        $dept = Department::factory()->create(['name' => 'Sales']);
        Employee::query()->whereIn('id', [$this->org['lead']->id, $this->org['worker']->id, $this->org['peer']->id])
            ->update(['department_id' => $dept->id]);
        $this->terminate($this->org['worker'], '2026-07-20');
        $exitWave = SurveyWave::query()->where('subject_employee_id', $this->org['worker']->id)->sole();
        $this->answer($exitWave, $this->org['worker']->refresh(), $this->answers);
        $this->actingAs($this->hr)->postJson("/api/pulse/waves/{$exitWave->id}/close")->assertOk();

        // A manual wave of the same (lifecycle) survey, as it could exist from before the rule below.
        $manual = $this->wave($this->exit, [
            'anonymous' => false, 'min_group_size' => 1,
            'audience' => ['branch_ids' => [], 'department_ids' => [$dept->id]],
        ]);
        // A different rating, so a leaked "previous" is recognisably the leaving person's own 4.
        $this->answer($manual, $this->org['peer']->refresh(), ['reason' => 1, 'liked' => 'Pay', 'disliked' => '', 'manager' => 2, 'return' => 0]);
        $this->actingAs($this->hr)->postJson("/api/pulse/waves/{$manual->id}/close")->assertOk();

        $manager = $this->userOf($this->org['lead']);
        $this->actingAs($manager)->getJson("/api/pulse/waves/{$manual->id}/compare?with={$exitWave->id}")->assertForbidden();
        $this->actingAs($manager)->getJson("/api/pulse/waves/{$manual->id}/results")->assertForbidden();

        // Even for HR a lifecycle wave is never the other side of a comparison.
        $data = $this->actingAs($this->hr)->getJson("/api/pulse/waves/{$manual->id}/compare?with={$exitWave->id}")
            ->assertOk()->json('data');
        $this->assertIsArray($data);
        $this->assertNull($data['previous']);
        foreach ($data['rows'] as $row) {
            foreach ($row['questions'] as $cell) {
                $this->assertNull($cell['previous']);
                $this->assertNull($cell['delta']);
            }
        }
    }

    public function test_a_lifecycle_survey_takes_no_manual_waves_and_no_survey_becomes_one_after_its_waves(): void
    {
        $wave = [
            'starts_at' => '2026-07-14 00:00:00', 'ends_at' => '2026-07-18 00:00:00', 'schedule' => 'once',
            'audience' => ['branch_ids' => [], 'department_ids' => []], 'anonymous' => false, 'min_group_size' => 1,
        ];
        $this->actingAs($this->hr)->postJson("/api/pulse/surveys/{$this->exit->id}/waves", $wave)
            ->assertStatus(409)->assertJsonPath('code', 'lifecycle_survey');

        $team = $this->survey(['title' => 'Team pulse']);
        $this->actingAs($this->hr)->postJson("/api/pulse/surveys/{$team->id}/waves", $wave)->assertCreated();
        $payload = ['title' => 'Team pulse', 'type' => 'lifecycle', 'lifecycle_trigger' => 'exit', 'questions' => $team->questions];
        $this->actingAs($this->hr)->putJson("/api/pulse/surveys/{$team->id}", $payload)
            ->assertStatus(409)->assertJsonPath('code', 'has_waves');
        $this->assertSame('engagement', $team->refresh()->type->value);

        // A survey without waves may still become a lifecycle one.
        $fresh = $this->survey(['title' => 'Fresh pulse']);
        $this->actingAs($this->hr)->putJson("/api/pulse/surveys/{$fresh->id}", ['title' => 'Fresh pulse', 'type' => 'lifecycle', 'lifecycle_trigger' => 'exit', 'questions' => $fresh->questions])
            ->assertOk();
    }

    public function test_migration_moves_saved_old_template_surveys_to_five_questions_without_touching_answers(): void
    {
        // the built-in template before 2026-10-07, keys in another order (a JSON column does not keep it)
        $old = [
            ['required' => true, 'id' => 'reason', 'type' => 'single', 'text' => 'Головна причина звільнення', 'options' => ['Зарплата', 'Керівник', 'Задачі', 'Кар\'єрне зростання', 'Особисті обставини', 'Інше']],
            ['id' => 'enps', 'type' => 'enps', 'text' => 'Чи порекомендуєте ви нас як роботодавця?', 'required' => true],
            ['id' => 'comment', 'type' => 'text', 'text' => 'Що ми могли зробити інакше?', 'required' => false],
        ];
        $rewritten = $old;
        $rewritten[2]['text'] = 'Що нам змінити?'; // HR's own wording, same ids
        $this->exit->delete();
        $fresh = $this->survey(['title' => 'Fresh', 'type' => 'lifecycle', 'lifecycle_trigger' => 'exit', 'questions' => $old]);
        $used = $this->survey(['title' => 'Used', 'type' => 'lifecycle', 'lifecycle_trigger' => 'exit', 'questions' => $old]);
        $custom = $this->survey(['title' => 'Custom', 'type' => 'lifecycle', 'lifecycle_trigger' => 'exit', 'questions' => $rewritten]);
        $wave = $this->wave($used, ['anonymous' => false, 'min_group_size' => 1, 'subject_employee_id' => $this->org['worker']->id, 'trigger_key' => 'exit:2026-07-01']);
        $this->answer($wave, $this->org['worker'], ['reason' => 1, 'enps' => 7]);

        $migration = require base_path('app/Modules/Pulse/Database/Migrations/2026_10_25_100002_exit_survey_five_questions.php');
        $migration->up();
        $migration->up(); // idempotent

        $five = ['reason', 'liked', 'disliked', 'manager', 'return'];
        $this->assertSame($five, array_column($fresh->refresh()->questions, 'id'));
        $this->assertTrue($fresh->active);
        $this->assertSame(['reason', 'enps', 'comment'], array_column($used->refresh()->questions, 'id'), 'answered survey keeps its questions');
        $this->assertFalse($used->active);
        $copy = Survey::query()->where('title', 'Used')->where('active', true)->sole();
        $this->assertSame($five, array_column($copy->questions, 'id'));
        $this->assertSame('Що нам змінити?', $custom->refresh()->questions[2]['text'], 'rewritten by HR with the same ids: untouched');
        $this->assertTrue($custom->active);
        $this->assertSame(1, (int) DB::table('survey_responses')->where('wave_id', $wave->id)->count());
    }

    private function terminate(Employee $employee, string $firedAt): void
    {
        $this->actingAs($this->hr)->postJson("/api/people/{$employee->id}/terminate", ['fired_at' => $firedAt])->assertOk();
    }
}
