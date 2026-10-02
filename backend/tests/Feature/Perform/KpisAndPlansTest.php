<?php

declare(strict_types=1);

namespace Tests\Feature\Perform;

use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** KPIs and development plans: managers write, employees read (and tick own plan actions), strangers see nothing. */
final class KpisAndPlansTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_kpis_access_and_attainment(): void
    {
        ['lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org();
        $body = ['employee_id' => $worker->id, 'metric' => 'Calls', 'unit' => 'pcs', 'period' => '2026-10', 'target' => 200, 'actual' => 150];

        $this->actingAs($this->userOf($worker))->postJson('/api/perform/kpis', $body)->assertForbidden();
        $id = $this->actingAs($this->userOf($lead))->postJson('/api/perform/kpis', $body)
            ->assertCreated()->assertJsonPath('data.attainment', 75)->json('data.id');
        $this->actingAs($this->userOf($lead))->postJson('/api/perform/kpis', $body)->assertConflict()->assertJsonPath('code', 'duplicate');

        $this->actingAs($this->userOf($worker))->getJson('/api/perform/kpis?employee_id='.$worker->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_edit', false)->assertJsonPath('data.0.target', 200);
        $this->actingAs($this->userOf($peer))->getJson('/api/perform/kpis')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->userOf($peer))->putJson("/api/perform/kpis/{$id}", $body)->assertNotFound();
        $this->actingAs($this->login(UserRole::Admin))->putJson("/api/perform/kpis/{$id}", ['actual' => 220] + $body)
            ->assertOk()->assertJsonPath('data.attainment', 110);
        $this->actingAs($this->userOf($worker))->getJson('/api/perform/kpis?period=2026-1')->assertUnprocessable();
    }

    public function test_manager_deletes_a_kpi(): void
    {
        ['lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org();
        $body = ['employee_id' => $worker->id, 'metric' => 'Calls', 'period' => '2026-10', 'target' => 10];
        $id = $this->actingAs($this->userOf($lead))->postJson('/api/perform/kpis', $body)->assertCreated()->json('data.id');
        $kept = $this->actingAs($this->userOf($lead))->postJson('/api/perform/kpis', ['metric' => 'Meetings'] + $body)->assertCreated()->json('data.id');

        // The employee sees the KPI but cannot delete it; a stranger does not see it at all.
        $this->actingAs($this->userOf($worker))->deleteJson("/api/perform/kpis/{$id}")->assertForbidden();
        $this->actingAs($this->userOf($peer))->deleteJson("/api/perform/kpis/{$id}")->assertNotFound();
        $this->assertDatabaseHas('kpis', ['id' => $id]);

        $this->actingAs($this->userOf($lead))->deleteJson("/api/perform/kpis/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('kpis', ['id' => $id]);
        $this->assertDatabaseHas('kpis', ['id' => $kept]);
        $this->actingAs($this->userOf($lead))->getJson('/api/perform/kpis?employee_id='.$worker->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kept);
        $this->actingAs($this->userOf($lead))->deleteJson("/api/perform/kpis/{$id}")->assertNotFound();
    }

    public function test_manager_updates_a_development_plan(): void
    {
        ['lead' => $lead, 'worker' => $worker] = $this->org();
        $id = $this->actingAs($this->userOf($lead))->postJson('/api/perform/development-plans', [
            'employee_id' => $worker->id, 'title' => 'Grow', 'goals' => [], 'actions' => [['id' => 'a1', 'text' => 'Course']],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->userOf($lead))->putJson("/api/perform/development-plans/{$id}", [
            'employee_id' => (string) $worker->id,
            'title' => 'Grow into a team lead',
            'goals' => [['id' => 'g1', 'text' => 'Lead a small project']],
            'actions' => [['id' => 'a1', 'text' => 'Management course', 'done' => true], ['id' => 'a2', 'text' => 'Mentor an intern', 'due_on' => '2026-12-01']],
            'due_on' => '2027-03-01',
            'status' => 'completed',
        ])->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.employee.id', $worker->id)
            ->assertJsonPath('data.title', 'Grow into a team lead')
            ->assertJsonPath('data.goals.0.id', 'g1')
            ->assertJsonPath('data.goals.0.text', 'Lead a small project')
            ->assertJsonPath('data.actions.0.id', 'a1')
            ->assertJsonPath('data.actions.0.done', true)
            ->assertJsonPath('data.actions.1.id', 'a2')
            ->assertJsonPath('data.actions.1.due_on', '2026-12-01')
            ->assertJsonPath('data.due_on', '2027-03-01')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress', ['done' => 1, 'total' => 2])
            ->assertJsonPath('data.can_edit', true);

        $this->assertDatabaseHas('development_plans', ['id' => $id, 'title' => 'Grow into a team lead', 'status' => 'completed']);
    }

    public function test_development_plans(): void
    {
        ['lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org();
        $body = [
            'employee_id' => $worker->id,
            'title' => 'Grow into a team lead',
            'goals' => [['text' => 'Lead a small project']],
            'actions' => [['id' => 'a1', 'text' => 'Management course', 'due_on' => '2026-12-01'], ['text' => 'Mentor an intern']],
            'due_on' => '2027-03-01',
        ];
        $this->actingAs($this->userOf($worker))->postJson('/api/perform/development-plans', $body)->assertForbidden();
        $id = $this->actingAs($this->userOf($lead))->postJson('/api/perform/development-plans', $body)
            ->assertCreated()->assertJsonPath('data.progress', ['done' => 0, 'total' => 2])->json('data.id');

        $this->actingAs($this->userOf($worker))->patchJson("/api/perform/development-plans/{$id}/actions/a1", ['done' => true])
            ->assertOk()->assertJsonPath('data.progress.done', 1)->assertJsonPath('data.can_edit', false);
        $this->actingAs($this->userOf($worker))->putJson("/api/perform/development-plans/{$id}", $body)->assertForbidden();
        $this->actingAs($this->userOf($worker))->patchJson("/api/perform/development-plans/{$id}/actions/missing", ['done' => true])->assertNotFound();
        $this->actingAs($this->userOf($peer))->patchJson("/api/perform/development-plans/{$id}/actions/a1", ['done' => false])->assertNotFound();
        $this->actingAs($this->userOf($peer))->getJson('/api/perform/development-plans')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->userOf($lead))->deleteJson("/api/perform/development-plans/{$id}")->assertNoContent();
    }
}
