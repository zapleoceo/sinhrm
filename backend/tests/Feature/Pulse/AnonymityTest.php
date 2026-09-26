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

        // Open anonymous wave: participation only.
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/results")->assertOk()
            ->assertJsonPath('data.state', 'open')->assertJsonPath('data.responses', null)
            ->assertJsonPath('data.participation.responded_bucket', '5–9');
        $this->actingAs($admin)->postJson("/api/pulse/waves/{$wave->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        // Reports carry aggregates only: no ids, names or hashes.
        $body = (string) $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/results?segment=department")->assertOk()
            ->assertJsonPath('data.state', 'closed')->assertJsonPath('data.responses', 5)->getContent();
        foreach ($people as $person) {
            $this->assertStringNotContainsString($person->full_name, $body);
        }
        foreach ($rows as $row) {
            $this->assertStringNotContainsString((string) $row->respondent_hash, $body);
        }
        $this->assertStringNotContainsString('employee_id', $body);
        $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/responses")->assertConflict()->assertJsonPath('code', 'anonymous_wave');

        // Closing wiped the salt: the hashes can never be recomputed; closing is final.
        $this->actingAs($admin)->postJson("/api/pulse/waves/{$wave->id}/close")->assertConflict();
        $this->assertNull(DB::table('survey_waves')->where('id', $wave->id)->value('salt'));
        $this->actingAs($this->userOf($people[1]))->postJson("/api/pulse/waves/{$wave->id}/responses", ['answers' => ['enps' => 1, 'q1' => 1]])
            ->assertConflict()->assertJsonPath('code', 'wave_not_open');
    }

    public function test_sequential_answers_during_an_open_wave_reveal_nothing(): void
    {
        $sales = Department::factory()->create(['name' => 'Sales']);
        $wave = $this->wave($this->survey());
        $admin = $this->login(UserRole::Admin);
        ['lead' => $lead] = $this->org();
        $lead->update(['department_id' => $sales->id]);
        $people = $this->people(7, ['department_id' => $sales->id]);

        $snapshots = [];
        foreach ($people as $i => $p) {
            $this->answer($wave, $p, ['enps' => $i % 11, 'q1' => $i % 5 + 1, 'txt' => 'Secret '.$i]);
            foreach ([
                $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/results?segment=department"),
                $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/compare"),
                $this->actingAs($this->userOf($lead))->getJson("/api/pulse/waves/{$wave->id}/results"),
            ] as $response) {
                $data = (array) $response->assertOk()->json('data');
                $this->assertSame('open', $data['state']);
                $this->assertNull($data['responses']);
                $this->assertSame([], $data['questions']);
                $this->assertArrayNotHasKey('segments', $data);
                $this->assertArrayNotHasKey('rows', $data);
                $this->assertStringNotContainsString('Secret', (string) $response->getContent());
                $snapshots[] = $data['participation']['responded_bucket'];
            }
        }
        // Only the coarse range moved (0–4 → 5–9), never a score.
        $this->assertSame(['0–4', '5–9'], array_values(array_unique($snapshots)));

        $this->actingAs($admin)->postJson("/api/pulse/waves/{$wave->id}/close")->assertOk();
        $closed = $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/results")->assertOk()
            ->assertJsonPath('data.state', 'closed')->assertJsonPath('data.responses', 7);
        $this->assertCount(7, (array) $closed->json('data.questions.4.texts'));
        $this->actingAs($this->userOf($lead))->getJson("/api/pulse/waves/{$wave->id}/results")->assertOk()
            ->assertJsonPath('data.scope', 'department')->assertJsonPath('data.responses', 7);
    }

    public function test_results_are_suppressed_below_the_minimum_group(): void
    {
        $sales = Department::factory()->create(['name' => 'Sales']);
        $admin = $this->login(UserRole::Admin);

        $small = $this->wave($this->survey());
        foreach ($this->people(4, ['department_id' => $sales->id]) as $p) {
            $this->answer($small, $p, ['enps' => 9, 'q1' => 4, 'txt' => 'Secret words']);
        }
        $small->update(['status' => 'closed', 'salt' => null]);
        $body = (string) $this->actingAs($admin)->getJson("/api/pulse/waves/{$small->id}/results")->assertOk()
            ->assertJsonPath('data.suppressed', true)->assertJsonPath('data.responses', null)->assertJsonPath('data.questions', [])->getContent();
        $this->assertStringNotContainsString('Secret words', $body);

        // Five answers: shown; the optional text answered by only two is hidden on its own.
        $wave = $this->wave($this->survey());
        foreach ($this->people(5) as $i => $p) {
            $this->answer($wave, $p, ['enps' => $i === 0 ? 3 : 9, 'q1' => 2] + ($i < 2 ? ['txt' => 'Few words'] : []));
        }
        $wave->update(['status' => 'closed', 'salt' => null]);
        $result = $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/results")->assertOk()
            ->assertJsonPath('data.suppressed', false)->assertJsonPath('data.responses', 5)
            ->assertJsonPath('data.questions.0.enps.score', 60) // 4 promoters, 1 detractor of 5
            ->assertJsonPath('data.questions.4.suppressed', true)
            ->assertJsonPath('data.questions.4.answered', null);
        $this->assertStringNotContainsString('Few words', (string) $result->getContent());
    }

    public function test_segments_are_left_out_when_they_or_their_complement_are_small(): void
    {
        $a = Department::factory()->create(['name' => 'Alpha']);
        $b = Department::factory()->create(['name' => 'Beta']);
        $c = Department::factory()->create(['name' => 'Gamma']);
        $admin = $this->login(UserRole::Admin);
        $survey = $this->survey();
        $wave = $this->wave($survey);
        foreach ([[$a, 6, 5], [$b, 5, 1], [$c, 2, 3]] as [$dept, $n, $score]) {
            foreach ($this->people($n, ['department_id' => $dept->id]) as $p) {
                $this->answer($wave, $p, ['enps' => 9, 'q1' => $score]);
            }
        }
        $wave->update(['status' => 'closed', 'salt' => null]);

        // Gamma (2) is below 5. Alpha + Beta shown would leave 2 hidden → Beta (the smaller) is hidden too, so the
        // hidden rest (Beta + Gamma = 7) cannot be isolated by subtraction; Alpha (6) and its complement (7) are ≥ 5.
        $results = $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/results?segment=department")->assertOk();
        $this->assertSame(['Alpha'], array_column((array) $results->json('data.segments'), 'name'));
        $body = (string) $results->getContent();
        $this->assertStringNotContainsString('Beta', $body);
        $this->assertStringNotContainsString('Gamma', $body);

        $compare = $this->actingAs($admin)->getJson("/api/pulse/waves/{$wave->id}/compare?segment=department")->assertOk();
        $this->assertSame([null, 'Alpha'], array_column((array) $compare->json('data.rows'), 'name'));
        $this->assertStringNotContainsString('Gamma', (string) $compare->getContent());
    }

    public function test_min_group_can_only_be_raised_while_the_wave_is_not_closed(): void
    {
        $admin = $this->login(UserRole::Admin);
        $wave = $this->wave($this->survey());
        foreach ($this->people(5) as $p) {
            $this->answer($wave, $p, ['enps' => 9, 'q1' => 4]);
        }
        $url = "/api/pulse/waves/{$wave->id}";
        $this->actingAs($admin)->putJson($url, ['min_group_size' => 4])->assertUnprocessable()->assertJsonPath('code', 'min_group_lower');
        $this->actingAs($admin)->putJson($url, ['min_group_size' => 6])->assertOk()->assertJsonPath('data.min_group_size', 6);
        $this->actingAs($this->login())->putJson($url, ['min_group_size' => 9])->assertForbidden();

        $this->actingAs($admin)->postJson("{$url}/close")->assertOk();
        $this->actingAs($admin)->getJson("{$url}/results")->assertOk()->assertJsonPath('data.suppressed', true);
        $this->actingAs($admin)->putJson($url, ['min_group_size' => 10])->assertConflict()->assertJsonPath('code', 'wave_not_open');
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
        $wave->update(['status' => 'closed', 'salt' => null]);

        $this->actingAs($this->userOf($lead))->getJson("/api/pulse/waves/{$wave->id}/results?segment=branch")->assertOk()
            ->assertJsonPath('data.scope', 'department')->assertJsonPath('data.responses', 5)
            ->assertJsonPath('data.questions.0.enps.score', 100)->assertJsonMissingPath('data.segments');
        $this->actingAs($this->userOf($worker))->getJson("/api/pulse/waves/{$wave->id}/results")->assertForbidden();
        $this->actingAs($this->userOf($worker))->getJson("/api/pulse/waves/{$wave->id}/compare")->assertForbidden();
        $this->assertSame(10, SurveyWave::query()->withCount('responses')->findOrFail($wave->id)->responses_count);
    }
}
