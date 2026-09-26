<?php

declare(strict_types=1);

namespace Tests\Feature\Pulse;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Department;
use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PeopleFixtures;
use Tests\Support\PulseFixtures;
use Tests\TestCase;

/**
 * Anonymity guarantees, checked in the database and in every API answer:
 * no employee id stored, no timestamps, hashes not traceable after close, results suppressed below the minimum
 * group, individual responses unavailable, one answer per person.
 */
final class AnonymityTest extends TestCase
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

    public function test_anonymous_responses_store_no_identity(): void
    {
        $survey = $this->survey();
        $wave = $this->wave($survey);
        $people = $this->people(5);
        foreach ($people as $i => $person) {
            $this->answer($wave, $person, ['enps' => 10 - $i, 'q1' => 5, 'txt' => 'Note '.$i]);
        }

        $rows = DB::table('survey_responses')->where('wave_id', $wave->id)->get();
        $this->assertCount(5, $rows);
        $this->assertFalse(Schema::hasColumn('survey_responses', 'created_at'), 'no submit timestamps');
        foreach ($rows as $row) {
            $this->assertNull($row->employee_id);
            $this->assertSame(64, strlen((string) $row->respondent_hash));
            foreach ($people as $person) {
                $this->assertNotSame(hash('sha256', (string) $person->id), $row->respondent_hash);
            }
        }

        // A second answer is refused; "my waves" shows it as done.
        $this->actingAs($this->userOf($people[0]))->postJson("/api/pulse/waves/{$wave->id}/responses", ['answers' => ['enps' => 1, 'q1' => 1]])
            ->assertConflict()->assertJsonPath('code', 'already_responded');
        $this->actingAs($this->userOf($people[0]))->getJson('/api/pulse/my/waves')->assertOk()
            ->assertJsonPath('data.0.responded', true)->assertJsonMissingPath('data.0.responses_count');

        // Reports carry aggregates only: no ids, names or hashes.
        $admin = $this->login(UserRole::Admin);
        $body = (string) $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/results?segment=department")->assertOk()
            ->assertJsonPath('data.responses', 5)->getContent();
        foreach ($people as $person) {
            $this->assertStringNotContainsString($person->full_name, $body);
        }
        foreach ($rows as $row) {
            $this->assertStringNotContainsString((string) $row->respondent_hash, $body);
        }
        $this->assertStringNotContainsString('employee_id', $body);
        $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/responses")->assertConflict()->assertJsonPath('code', 'anonymous_wave');

        // Closing wipes the salt: the hashes can never be recomputed.
        $this->actingAs($admin)->postJson("/api/pulse/waves/{$wave->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertNull(DB::table('survey_waves')->where('id', $wave->id)->value('salt'));
        $this->actingAs($this->userOf($people[1]))->postJson("/api/pulse/waves/{$wave->id}/responses", ['answers' => ['enps' => 1, 'q1' => 1]])
            ->assertConflict()->assertJsonPath('code', 'wave_not_open');
    }

    public function test_results_are_suppressed_below_the_minimum_group(): void
    {
        $sales = Department::factory()->create(['name' => 'Sales']);
        $ops = Department::factory()->create(['name' => 'Ops']);
        $wave = $this->wave($this->survey());
        $admin = $this->login(UserRole::Admin);
        $four = $this->people(4, ['department_id' => $sales->id]);
        foreach ($four as $p) {
            $this->answer($wave, $p, ['enps' => 9, 'q1' => 4, 'txt' => 'Secret words']);
        }
        $body = (string) $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/results")->assertOk()
            ->assertJsonPath('data.suppressed', true)->assertJsonPath('data.responses', null)->assertJsonPath('data.questions', [])->getContent();
        $this->assertStringNotContainsString('Secret words', $body);

        [$fifth] = $this->people(1, ['department_id' => $ops->id]);
        $this->answer($wave, $fifth, ['enps' => 3, 'q1' => 2]);
        $result = $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/results?segment=department")->assertOk()
            ->assertJsonPath('data.suppressed', false)->assertJsonPath('data.responses', 5)
            ->assertJsonPath('data.questions.0.enps.score', 60); // 4 promoters, 1 detractor of 5
        $segments = collect((array) $result->json('data.segments'))->keyBy('name');
        // Sales has 4 answers, Ops has 1: both below 5 → suppressed.
        $this->assertTrue($segments['Sales']['suppressed']);
        $this->assertNull($segments['Sales']['responses']);
        $this->assertTrue($segments['Ops']['suppressed']);
    }

    public function test_audience_and_non_anonymous_waves(): void
    {
        $sales = Department::factory()->create();
        $wave = $this->wave($this->survey(), ['audience' => ['branch_ids' => [], 'department_ids' => [$sales->id]], 'anonymous' => false, 'min_group_size' => 1]);
        [$inside] = $this->people(1, ['department_id' => $sales->id]);
        [$outside] = $this->people(1);

        $this->actingAs($this->userOf($outside))->getJson('/api/pulse/my/waves')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->userOf($outside))->getJson("/api/pulse/waves/{$wave->id}/form")->assertForbidden()->assertJsonPath('code', 'not_in_audience');
        $this->actingAs($this->userOf($inside))->getJson("/api/pulse/waves/{$wave->id}/form")->assertOk()->assertJsonCount(5, 'data.questions');
        $this->actingAs($this->userOf($inside))->postJson("/api/pulse/waves/{$wave->id}/responses", ['answers' => ['enps' => 11, 'q1' => 0, 'pick' => 5]])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_answers')->assertJsonPath('questions', ['enps', 'q1', 'pick']);
        $this->answer($wave, $inside, ['enps' => '8', 'q1' => 3, 'tags' => [2, 0, 2]]);

        $this->assertSame($inside->id, DB::table('survey_responses')->value('employee_id'));
        $this->actingAs($this->login(UserRole::Admin))->getJson("/api/pulse/waves/{$wave->id}/responses")->assertOk()
            ->assertJsonPath('data.0.employee_id', $inside->id)
            ->assertJsonPath('data.0.answers.enps', 8)
            ->assertJsonPath('data.0.answers.tags', [0, 2]);
        $this->actingAs($this->userOf($inside))->getJson("/api/pulse/waves/{$wave->id}/responses")->assertForbidden();
    }

    public function test_manager_sees_only_own_department_and_employees_see_no_reports(): void
    {
        $sales = Department::factory()->create();
        $ops = Department::factory()->create();
        ['lead' => $lead, 'worker' => $worker] = $this->org();
        $lead->update(['department_id' => $sales->id]);
        $wave = $this->wave($this->survey());
        foreach ($this->people(5, ['department_id' => $sales->id]) as $p) {
            $this->answer($wave, $p, ['enps' => 10, 'q1' => 5]);
        }
        foreach ($this->people(5, ['department_id' => $ops->id]) as $p) {
            $this->answer($wave, $p, ['enps' => 0, 'q1' => 1]);
        }

        $this->actingAs($this->userOf($lead))->getJson("/api/pulse/waves/{$wave->id}/results?segment=branch")->assertOk()
            ->assertJsonPath('data.scope', 'department')->assertJsonPath('data.responses', 5)
            ->assertJsonPath('data.questions.0.enps.score', 100)->assertJsonMissingPath('data.segments');
        $this->actingAs($this->userOf($worker))->getJson("/api/pulse/waves/{$wave->id}/results")->assertForbidden();
        $this->actingAs($this->userOf($worker))->getJson("/api/pulse/waves/{$wave->id}/compare")->assertForbidden();
        $this->assertSame(10, SurveyWave::query()->withCount('responses')->findOrFail($wave->id)->responses_count);
    }
}
