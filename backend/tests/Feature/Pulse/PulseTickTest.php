<?php

declare(strict_types=1);

namespace Tests\Feature\Pulse;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\Support\PulseFixtures;
use Tests\TestCase;

/** "pulse.tick": opening/closing waves, recurring successors (once), lifecycle triggers (idempotent). */
final class PulseTickTest extends TestCase
{
    use PeopleFixtures, PulseFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_opens_closes_and_schedules_the_next_wave_once(): void
    {
        $survey = $this->survey();
        $scheduled = $this->wave($survey, ['status' => 'scheduled', 'starts_at' => '2026-10-05 08:00:00', 'ends_at' => '2026-10-09']);
        $ended = $this->wave($survey, ['schedule' => 'monthly', 'starts_at' => '2026-09-05 09:00:00', 'ends_at' => '2026-09-12 09:00:00']);

        $tick = $this->pulseTick();
        $this->assertSame(1, $tick['waves_opened']);
        $this->assertSame(1, $tick['waves_closed']);
        $this->assertSame(1, $tick['waves_scheduled']);
        $this->assertSame('open', $scheduled->refresh()->status->value);
        $this->assertSame('closed', $ended->refresh()->status->value);
        $this->assertNull($ended->salt);

        $next = SurveyWave::query()->where('parent_wave_id', $ended->id)->firstOrFail();
        $this->assertSame('2026-10-05 09:00:00', $next->starts_at->toDateTimeString());
        $this->assertSame('2026-10-12 09:00:00', $next->ends_at->toDateTimeString());
        $this->assertSame('open', $next->status->value, 'its start has already come');
        $this->assertNotNull($next->salt);

        // Idempotent: a second run changes nothing.
        $again = $this->pulseTick();
        $this->assertSame(0, $again['waves_closed']);
        $this->assertSame(1, SurveyWave::query()->where('parent_wave_id', $ended->id)->count());
    }

    public function test_hire_anniversary_surveys_start_once(): void
    {
        $survey = $this->survey(['type' => 'lifecycle', 'lifecycle_trigger' => 'hire_30']);
        $this->survey(['type' => 'lifecycle', 'lifecycle_trigger' => 'hire_90', 'active' => false]);
        $new = $this->employee(['hired_at' => '2026-09-03'], $this->login()); // day 32: inside the catch-up window
        $this->employee(['hired_at' => '2026-08-01']); // day 65: nothing

        $this->assertSame(1, $this->pulseTick()['lifecycle_started']);
        $this->assertSame(0, $this->pulseTick()['lifecycle_started']);
        $wave = SurveyWave::query()->where('survey_id', $survey->id)->sole();
        $this->assertSame($new->id, $wave->subject_employee_id);
        $this->assertSame('hire_30:2026-09-03', $wave->trigger_key);
        $this->assertFalse($wave->anonymous);

        // Only the subject is asked; the wave is not a report for managers.
        $this->actingAs($this->userOf($new))->getJson('/api/pulse/my/waves')->assertOk()->assertJsonCount(1, 'data');
        [$colleague] = $this->people(1);
        $this->actingAs($this->userOf($colleague))->getJson('/api/pulse/my/waves')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_exit_survey_on_termination_once(): void
    {
        $survey = $this->survey(['type' => 'lifecycle', 'lifecycle_trigger' => 'exit']);
        ['worker' => $worker] = $this->org();
        $admin = $this->login(UserRole::Admin);

        $this->actingAs($admin)->postJson("/api/people/{$worker->id}/terminate", ['fired_at' => '2026-10-20', 'reason' => 'Relocation'])->assertOk();
        event(new EmployeeTerminated($worker->refresh()));

        $wave = SurveyWave::query()->where('survey_id', $survey->id)->sole();
        $this->assertSame('exit:2026-10-20', $wave->trigger_key);
        $this->assertSame('open', $wave->status->value);
        $this->actingAs($this->userOf($worker))->getJson("/api/pulse/waves/{$wave->id}/form")->assertOk();
    }
}
