<?php

declare(strict_types=1);

namespace Tests\Feature\Pulse;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\Support\PulseFixtures;
use Tests\TestCase;

/** eNPS through the API and wave-over-wave comparison, overall and per department (suppressed groups → null). */
final class EnpsAndCompareTest extends TestCase
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

    public function test_enps_distribution_choices_and_texts(): void
    {
        $wave = $this->wave($this->survey());
        $scores = [10, 9, 8, 7, 6, 0];
        foreach ($this->people(6) as $i => $p) {
            $this->answer($wave, $p, ['enps' => $scores[$i], 'q1' => $i % 5 + 1, 'pick' => $i % 2, 'tags' => [0, 1], 'txt' => 'zeta'.($i === 0 ? '' : $i)]);
        }
        $wave->update(['status' => 'closed', 'salt' => null]);
        $data = $this->actingAs($this->login(UserRole::Admin))->getJson("/api/pulse/waves/{$wave->id}/results")->assertOk()->json('data');

        $this->assertEquals(['score' => 0, 'promoters' => 2, 'passives' => 2, 'detractors' => 2, 'total' => 6], $data['questions'][0]['enps']);
        $this->assertEqualsWithDelta(2.67, $data['questions'][1]['average'], 0.001);
        $this->assertEquals(['1' => 2, '2' => 1, '3' => 1, '4' => 1, '5' => 1], $data['questions'][1]['distribution']);
        $this->assertEquals([['label' => 'Team', 'count' => 3], ['label' => 'Tasks', 'count' => 3], ['label' => 'Pay', 'count' => 0]], $data['questions'][2]['options']);
        $this->assertSame(6, $data['questions'][3]['options'][0]['count']);
        $this->assertSame(['zeta', 'zeta1', 'zeta2', 'zeta3', 'zeta4', 'zeta5'], $data['questions'][4]['texts']);
    }

    public function test_wave_over_wave_per_department(): void
    {
        $sales = Department::factory()->create(['name' => 'Sales']);
        $ops = Department::factory()->create(['name' => 'Ops']);
        $survey = $this->survey();
        $first = $this->wave($survey, ['starts_at' => '2026-09-01', 'ends_at' => '2026-09-07', 'status' => 'closed', 'salt' => 'synthetic-salt-first']);
        $second = $this->wave($survey, ['starts_at' => '2026-10-01', 'ends_at' => '2026-10-07']);
        $salesPeople = $this->people(5, ['department_id' => $sales->id]);
        $opsPeople = $this->people(5, ['department_id' => $ops->id]);

        // First wave answered while open: Sales detractors, the ops pair passives.
        $first->update(['status' => 'open']);
        foreach ($salesPeople as $p) {
            $this->answer($first, $p, ['enps' => 5, 'q1' => 2]);
        }
        foreach ($opsPeople as $p) {
            $this->answer($first, $p, ['enps' => 7, 'q1' => 3]);
        }
        $first->update(['status' => 'closed', 'salt' => null]);
        foreach ($salesPeople as $p) {
            $this->answer($second, $p, ['enps' => 10, 'q1' => 4]);
        }
        foreach ($opsPeople as $p) {
            $this->answer($second, $p, ['enps' => 9, 'q1' => 5]);
        }
        $admin = $this->login(UserRole::Admin);
        // Still open: nothing to compare yet.
        $this->actingAs($admin)->getJson("/api/pulse/waves/{$second->id}/compare")->assertOk()
            ->assertJsonPath('data.state', 'open')->assertJsonMissingPath('data.rows');
        $second->update(['status' => 'closed', 'salt' => null]);

        $data = $this->actingAs($admin)->getJson("/api/pulse/waves/{$second->id}/compare?segment=department")->assertOk()->json('data');
        $this->assertSame($first->id, $data['previous']['id']);
        $this->assertSame(['enps', 'q1'], array_column($data['questions'], 'id'));
        $this->assertIsArray($data['rows']);
        $rows = [];
        foreach ($data['rows'] as $row) {
            $rows[$row['name'] ?? 'all'] = $row;
        }

        // Overall: eNPS −50 → 100 (5 detractors + 5 passives → all promoters); q1 average 2.5 → 4.5.
        $this->assertEquals(['id' => 'enps', 'current' => 100.0, 'previous' => -50.0, 'delta' => 150.0], $rows['all']['questions'][0]);
        $this->assertEquals(['id' => 'q1', 'current' => 4.5, 'previous' => 2.5, 'delta' => 2.0], $rows['all']['questions'][1]);
        // Both departments have 5 answers and a complement of 5: shown.
        $this->assertEquals(['id' => 'q1', 'current' => 4.0, 'previous' => 2.0, 'delta' => 2.0], $rows['Sales']['questions'][1]);
        $this->assertEquals(['id' => 'q1', 'current' => 5.0, 'previous' => 3.0, 'delta' => 2.0], $rows['Ops']['questions'][1]);
    }

    /**
     * Differencing attack: wave 2 = the same 5 Sales people plus one newcomer. Without protection
     * 6 × avg(wave 2) − 5 × avg(wave 1) = the newcomer's own answer. The comparison must hide it.
     */
    public function test_one_newcomer_between_waves_does_not_leak_through_the_delta(): void
    {
        $sales = Department::factory()->create(['name' => 'Sales']);
        $ops = Department::factory()->create(['name' => 'Ops']);
        $survey = $this->survey();
        $first = $this->wave($survey, ['starts_at' => '2026-09-01', 'ends_at' => '2026-09-07']);
        $second = $this->wave($survey, ['starts_at' => '2026-10-01', 'ends_at' => '2026-10-07']);
        $salesPeople = $this->people(5, ['department_id' => $sales->id]);
        $opsPeople = $this->people(5, ['department_id' => $ops->id]);
        $newcomer = $this->people(1, ['department_id' => $sales->id])[0];
        foreach ([...$salesPeople, ...$opsPeople] as $p) {
            $this->answer($first, $p, ['enps' => 8, 'q1' => 3]);
            $this->answer($second, $p, ['enps' => 8, 'q1' => 3]);
        }
        $this->answer($second, $newcomer, ['enps' => 0, 'q1' => 1]);
        $first->update(['status' => 'closed', 'salt' => null]);
        $second->update(['status' => 'closed', 'salt' => null]);

        $data = $this->actingAs($this->login(UserRole::Admin))
            ->getJson("/api/pulse/waves/{$second->id}/compare?segment=department")->assertOk()->json('data');
        foreach ($data['rows'] as $row) {
            if ($row['name'] === 'Ops') {
                continue; // Ops is identical in both waves (difference 0), it may be compared.
            }
            $this->assertSame('anonymity', $row['hidden_reason'], (string) $row['name']);
            foreach ($row['questions'] as $cell) {
                $this->assertNull($cell['previous']);
                $this->assertNull($cell['delta']);
            }
        }
        $ops = array_values(array_filter($data['rows'], static fn (array $r): bool => $r['name'] === 'Ops'))[0];
        // Ops itself is unchanged, but the rest of the wave outside Ops gained the newcomer: still hidden.
        $this->assertSame('anonymity', $ops['hidden_reason']);
    }
}
