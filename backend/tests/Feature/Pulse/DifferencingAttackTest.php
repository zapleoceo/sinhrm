<?php

declare(strict_types=1);

namespace Tests\Feature\Pulse;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Department;
use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\PeopleFixtures;
use Tests\Support\PulseFixtures;
use Tests\TestCase;

/**
 * Differencing attacks across two closed waves of one survey (residual risks of PR #35):
 * (a) "one out, one in": equal answer counts, so the count check alone lets the comparison through;
 * (b) no comparison screen at all: the attacker reads two per-wave results pages and subtracts by hand.
 * Waves are closed through the admin API, which stores the audience snapshot.
 */
final class DifferencingAttackTest extends TestCase
{
    use PeopleFixtures, PulseFixtures, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->admin = $this->login(UserRole::Admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_one_out_one_in_swap_hides_the_comparison(): void
    {
        [$sales, $ops] = $this->departments();
        $survey = $this->survey();
        $first = $this->wave($survey, ['starts_at' => '2026-09-01', 'ends_at' => '2026-10-10']);
        $salesPeople = $this->people(5, ['department_id' => $sales->id]);
        $opsPeople = $this->people(5, ['department_id' => $ops->id]);
        foreach ([...$salesPeople, ...$opsPeople] as $p) {
            $this->answer($first, $p, ['enps' => 8, 'q1' => 3]);
        }
        $this->close($first);

        // One Sales person leaves, one newcomer joins Sales: 5 and 5 answers again.
        $salesPeople[0]->update(['status' => 'terminated']);
        $newcomer = $this->people(1, ['department_id' => $sales->id])[0];
        $second = $this->wave($survey, ['starts_at' => '2026-10-01', 'ends_at' => '2026-10-10']);
        foreach ([...array_slice($salesPeople, 1), ...$opsPeople] as $p) {
            $this->answer($second, $p, ['enps' => 8, 'q1' => 3]);
        }
        $this->answer($second, $newcomer, ['enps' => 0, 'q1' => 1]);
        $this->close($second);

        $data = $this->actingAs($this->admin)
            ->getJson("/api/pulse/waves/{$second->id}/compare?segment=department")->assertOk()->json('data');
        $this->assertNotEmpty($data['rows']);
        foreach ($data['rows'] as $row) {
            $this->assertSame('anonymity', $row['hidden_reason'], (string) ($row['name'] ?? 'total'));
            foreach ($row['questions'] as $cell) {
                $this->assertNull($cell['previous']);
                $this->assertNull($cell['delta']);
            }
        }
    }

    public function test_b_manual_subtraction_of_two_results_pages_is_blocked_for_segments(): void
    {
        [$sales, $ops] = $this->departments();
        $lead = $this->employee(['department_id' => $sales->id], $this->login());
        $this->employee(['manager_id' => $lead->id, 'department_id' => $ops->id]);
        $survey = $this->survey();
        $first = $this->wave($survey, ['starts_at' => '2026-09-01', 'ends_at' => '2026-10-10']);
        $salesPeople = $this->people(6, ['department_id' => $sales->id]);
        $opsPeople = $this->people(6, ['department_id' => $ops->id]);
        foreach ([...$salesPeople, ...$opsPeople] as $p) {
            $this->answer($first, $p, ['enps' => 8, 'q1' => 3]);
        }
        $this->close($first);

        $newcomer = $this->people(1, ['department_id' => $sales->id])[0];
        $second = $this->wave($survey, ['starts_at' => '2026-10-01', 'ends_at' => '2026-10-10']);
        foreach ([...$salesPeople, ...$opsPeople, $newcomer] as $p) {
            $this->answer($second, $p, ['enps' => 8, 'q1' => 3]);
        }
        $this->close($second);

        // The earlier wave is untouched: both departments are listed.
        $then = $this->actingAs($this->admin)->getJson("/api/pulse/waves/{$first->id}/results?segment=department")->assertOk()->json('data.segments');
        $this->assertSame(['Ops', 'Sales'], $this->names($then));

        // The later wave: Sales (+1 person) and Ops (the rest of the wave outside it, +1) are not listed, so
        // "Sales now − Sales then" cannot be computed from the two pages.
        $now = $this->actingAs($this->admin)->getJson("/api/pulse/waves/{$second->id}/results?segment=department")->assertOk()->json('data.segments');
        $this->assertSame([], $this->names($now));

        // The Sales manager's own view of the later wave carries no numbers either.
        $this->actingAs($this->userOf($lead))->getJson("/api/pulse/waves/{$second->id}/results")->assertOk()
            ->assertJsonPath('data.scope', 'department')->assertJsonPath('data.hidden_reason', 'anonymity')
            ->assertJsonPath('data.suppressed', true)->assertJsonPath('data.responses', null);

        // Nothing links the snapshot to answers: no fingerprint or employee id is ever in the API output.
        $member = (string) DB::table('survey_wave_members')->where('wave_id', $second->id)->value('member');
        $this->assertSame(64, strlen($member));
        $body = (string) $this->actingAs($this->admin)->getJson("/api/pulse/waves/{$second->id}/compare?segment=department")->getContent();
        $this->assertStringNotContainsString($member, $body);
    }

    public function test_a_stable_group_is_still_compared_and_listed(): void
    {
        [$sales, $ops] = $this->departments();
        $survey = $this->survey();
        $people = [...$this->people(5, ['department_id' => $sales->id]), ...$this->people(5, ['department_id' => $ops->id])];
        $waves = [];
        foreach (['2026-09-01', '2026-10-01'] as $start) {
            $wave = $this->wave($survey, ['starts_at' => $start, 'ends_at' => '2026-10-10']);
            foreach ($people as $p) {
                $this->answer($wave, $p, ['enps' => 8, 'q1' => 3]);
            }
            $this->close($wave);
            $waves[] = $wave;
        }

        $data = $this->actingAs($this->admin)->getJson("/api/pulse/waves/{$waves[1]->id}/compare?segment=department")->assertOk()->json('data');
        foreach ($data['rows'] as $row) {
            $this->assertNull($row['hidden_reason']);
        }
        $segments = $this->actingAs($this->admin)->getJson("/api/pulse/waves/{$waves[1]->id}/results?segment=department")->assertOk()->json('data.segments');
        $this->assertSame(['Ops', 'Sales'], $this->names($segments));
    }

    /** @return array{Department, Department} */
    private function departments(): array
    {
        return [Department::factory()->create(['name' => 'Sales']), Department::factory()->create(['name' => 'Ops'])];
    }

    private function close(SurveyWave $wave): void
    {
        $this->actingAs($this->admin)->postJson("/api/pulse/waves/{$wave->id}/close")->assertOk();
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<string>
     */
    private function names(array $segments): array
    {
        $names = array_map(static fn (array $s): string => (string) $s['name'], $segments);
        sort($names);

        return $names;
    }
}
