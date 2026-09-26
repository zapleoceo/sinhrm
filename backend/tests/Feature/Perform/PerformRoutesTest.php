<?php

declare(strict_types=1);

namespace Tests\Feature\Perform;

use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Perform everyday routes (1:1, objectives, KPI, feedback, plans, review forms): 401, HR roles vs plain roles,
 * manager subtree, 404 for unknown/foreign ids, 422 with string params, moving a record out of the writer's scope.
 */
final class PerformRoutesTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function objective(array $extra = []): array
    {
        return $extra + [
            'scope' => 'personal', 'period' => '2026-Q4', 'title' => 'Goal', 'visibility' => 'private',
            'key_results' => [['id' => 'kr1', 'title' => 'Done', 'start' => '0', 'target' => '4', 'current' => '1', 'weight' => '1']],
        ];
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'one-on-ones' => ['GET', '/api/perform/one-on-ones'];
        yield 'one-on-ones.store' => ['POST', '/api/perform/one-on-ones'];
        yield 'one-on-ones.show' => ['GET', '/api/perform/one-on-ones/1'];
        yield 'one-on-ones.update' => ['PATCH', '/api/perform/one-on-ones/1'];
        yield 'one-on-ones.destroy' => ['DELETE', '/api/perform/one-on-ones/1'];
        yield 'templates' => ['GET', '/api/perform/one-on-one-templates'];
        yield 'objectives' => ['GET', '/api/perform/objectives'];
        yield 'objectives.store' => ['POST', '/api/perform/objectives'];
        yield 'objectives.show' => ['GET', '/api/perform/objectives/1'];
        yield 'objectives.update' => ['PUT', '/api/perform/objectives/1'];
        yield 'objectives.destroy' => ['DELETE', '/api/perform/objectives/1'];
        yield 'objectives.check-in' => ['POST', '/api/perform/objectives/1/check-ins'];
        yield 'kpis' => ['GET', '/api/perform/kpis'];
        yield 'kpis.store' => ['POST', '/api/perform/kpis'];
        yield 'kpis.update' => ['PUT', '/api/perform/kpis/1'];
        yield 'kpis.destroy' => ['DELETE', '/api/perform/kpis/1'];
        yield 'feedback' => ['GET', '/api/perform/feedback'];
        yield 'feedback.store' => ['POST', '/api/perform/feedback'];
        yield 'plans' => ['GET', '/api/perform/development-plans'];
        yield 'plans.store' => ['POST', '/api/perform/development-plans'];
        yield 'plans.update' => ['PUT', '/api/perform/development-plans/1'];
        yield 'plans.destroy' => ['DELETE', '/api/perform/development-plans/1'];
        yield 'plans.action' => ['PATCH', '/api/perform/development-plans/1/actions/a1'];
        yield 'review.mine' => ['GET', '/api/perform/review/assignments'];
        yield 'review.show' => ['GET', '/api/perform/review/assignments/1'];
        yield 'review.submit' => ['POST', '/api/perform/review/assignments/1/submit'];
        yield 'review.results' => ['GET', '/api/perform/review/cycles/1/results/1'];
        yield 'review.employee-results' => ['GET', '/api/perform/review/employees/1/results'];
    }

    #[DataProvider('routes')]
    public function test_guest_gets_401(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    /** @return iterable<string, array{UserRole, bool}> */
    public static function roles(): iterable
    {
        yield 'superadmin' => [UserRole::Superadmin, true];
        yield 'admin' => [UserRole::Admin, true];
        yield 'hr_manager' => [UserRole::HrManager, true];
        yield 'recruiter' => [UserRole::Recruiter, false];
        yield 'employee' => [UserRole::Employee, false];
        yield 'viewer' => [UserRole::Viewer, false];
    }

    #[DataProvider('roles')]
    public function test_global_role_decides_access_to_someone_elses_records(UserRole $role, bool $hr): void
    {
        ['lead' => $lead, 'worker' => $worker] = $this->org();
        $leadUser = $this->userOf($lead);
        $meeting = $this->actingAs($leadUser)->postJson('/api/perform/one-on-ones', ['employee_id' => $worker->id, 'scheduled_at' => '2026-10-10 10:00'])->json('data.id');
        $kpi = $this->actingAs($leadUser)->postJson('/api/perform/kpis', ['employee_id' => $worker->id, 'metric' => 'Calls', 'period' => '2026-10', 'target' => 10])->json('data.id');
        $objective = $this->actingAs($this->userOf($worker))->postJson('/api/perform/objectives', $this->objective())->json('data.id');
        $plan = $this->actingAs($leadUser)->postJson('/api/perform/development-plans', ['employee_id' => $worker->id, 'title' => 'Plan', 'goals' => [], 'actions' => [['id' => 'a1', 'text' => 'Step']]])->json('data.id');

        $user = $this->login($role);
        $expect = static fn ($r) => $hr ? $r->assertOk() : $r->assertNotFound();
        $expect($this->actingAs($user)->getJson("/api/perform/one-on-ones/$meeting"));
        $expect($this->actingAs($user)->getJson("/api/perform/objectives/$objective"));
        $expect($this->actingAs($user)->putJson("/api/perform/kpis/$kpi", ['employee_id' => $worker->id, 'metric' => 'Calls', 'period' => '2026-10', 'target' => '12', 'actual' => '6']));
        $expect($this->actingAs($user)->patchJson("/api/perform/development-plans/$plan/actions/a1", ['done' => '1']));
        $this->actingAs($user)->getJson('/api/perform/kpis')->assertOk()->assertJsonCount($hr ? 1 : 0, 'data');
        $this->actingAs($user)->getJson('/api/perform/development-plans')->assertOk()->assertJsonCount($hr ? 1 : 0, 'data');
        $this->actingAs($user)->getJson('/api/perform/objectives')->assertOk()->assertJsonCount($hr ? 1 : 0, 'data');
        // Private 1:1 notes: never to HR either.
        $this->actingAs($user)->patchJson("/api/perform/one-on-ones/$meeting", ['notes_private_manager' => 'x'])->assertStatus($hr ? 403 : 404);
    }

    public function test_writer_cannot_move_a_record_out_of_their_subtree(): void
    {
        ['lead' => $lead, 'worker' => $worker, 'other' => $other] = $this->org();
        $leadUser = $this->userOf($lead);
        $kpiBody = ['employee_id' => $worker->id, 'metric' => 'Calls', 'period' => '2026-Q4', 'target' => 10];
        $kpi = $this->actingAs($leadUser)->postJson('/api/perform/kpis', $kpiBody)->assertCreated()->json('data.id');
        $this->actingAs($leadUser)->putJson("/api/perform/kpis/$kpi", ['employee_id' => $other->id] + $kpiBody)->assertForbidden();
        $this->assertDatabaseHas('kpis', ['id' => $kpi, 'employee_id' => $worker->id]);

        $planBody = ['employee_id' => $worker->id, 'title' => 'Plan', 'goals' => [], 'actions' => []];
        $plan = $this->actingAs($leadUser)->postJson('/api/perform/development-plans', $planBody)->assertCreated()->json('data.id');
        $this->actingAs($leadUser)->putJson("/api/perform/development-plans/$plan", ['employee_id' => $other->id] + $planBody)->assertForbidden();
        $this->assertDatabaseHas('development_plans', ['id' => $plan, 'employee_id' => $worker->id]);

        $objective = $this->actingAs($leadUser)->postJson('/api/perform/objectives', $this->objective(['owner_employee_id' => $worker->id]))->assertCreated()->json('data.id');
        $this->actingAs($leadUser)->putJson("/api/perform/objectives/$objective", $this->objective(['owner_employee_id' => $other->id]))->assertForbidden();
        $this->actingAs($leadUser)->putJson("/api/perform/objectives/$objective", $this->objective(['scope' => 'company', 'owner_employee_id' => null]))->assertForbidden();
        $this->assertDatabaseHas('objectives', ['id' => $objective, 'owner_employee_id' => $worker->id]);
    }

    public function test_list_filters_arrive_as_strings(): void
    {
        ['lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org();
        $leadUser = $this->userOf($lead);
        foreach ([$worker, $peer] as $e) {
            $this->actingAs($leadUser)->postJson('/api/perform/kpis', ['employee_id' => (string) $e->id, 'metric' => 'Calls', 'period' => '2026-10', 'target' => '10'])->assertCreated();
            $this->actingAs($leadUser)->postJson('/api/perform/one-on-ones', ['employee_id' => (string) $e->id, 'scheduled_at' => '2026-10-10 10:00'])->assertCreated();
        }
        $this->actingAs($leadUser)->getJson("/api/perform/kpis?employee_id={$worker->id}&period=2026-10")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($leadUser)->getJson("/api/perform/one-on-ones?employee_id={$peer->id}&status=scheduled")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($leadUser)->getJson('/api/perform/one-on-ones?status=done')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($leadUser)->getJson('/api/perform/kpis?employee_id=abc')->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->actingAs($leadUser)->getJson('/api/perform/objectives?period=Q4-2026')->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->actingAs($leadUser)->getJson('/api/perform/objectives?owner_employee_id=0')->assertUnprocessable();
        $this->actingAs($leadUser)->getJson('/api/perform/feedback')->assertOk();
    }

    public function test_unknown_ids_are_404(): void
    {
        ['worker' => $worker] = $this->org();
        $user = $this->login(UserRole::Admin);
        $this->actingAs($user)->getJson('/api/perform/one-on-ones/999999')->assertNotFound();
        $this->actingAs($user)->patchJson('/api/perform/one-on-ones/999999', ['status' => 'completed'])->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/perform/one-on-ones/999999')->assertNotFound();
        $this->actingAs($user)->getJson('/api/perform/objectives/999999')->assertNotFound();
        $this->actingAs($user)->putJson('/api/perform/objectives/999999', $this->objective(['owner_employee_id' => $worker->id]))->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/perform/objectives/999999')->assertNotFound();
        $this->actingAs($user)->postJson('/api/perform/objectives/999999/check-ins', ['key_results' => [['id' => 'kr1', 'current' => 1]]])->assertNotFound();
        $this->actingAs($user)->putJson('/api/perform/kpis/999999', ['employee_id' => $worker->id, 'metric' => 'x', 'period' => '2026-10', 'target' => 1])->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/perform/kpis/999999')->assertNotFound();
        $this->actingAs($user)->putJson('/api/perform/development-plans/999999', ['employee_id' => $worker->id, 'title' => 'x', 'goals' => [], 'actions' => []])->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/perform/development-plans/999999')->assertNotFound();
        $this->actingAs($user)->patchJson('/api/perform/development-plans/999999/actions/a1', ['done' => true])->assertNotFound();
        $this->actingAs($user)->getJson('/api/perform/review/employees/999999/results')->assertOk()->assertJsonCount(0, 'data');
        // Action ids outside the route pattern do not reach the controller.
        $this->actingAs($user)->patchJson('/api/perform/development-plans/1/actions/'.str_repeat('a', 33), ['done' => true])->assertNotFound();
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function invalidBodies(): iterable
    {
        yield '1:1 without date' => ['one-on-ones', ['employee_id' => 1], 'scheduled_at'];
        yield '1:1 bad date' => ['one-on-ones', ['employee_id' => 1, 'scheduled_at' => 'tomorrow-ish'], 'scheduled_at'];
        yield 'kpi period' => ['kpis', ['employee_id' => 1, 'metric' => 'x', 'period' => '2026-13', 'target' => 1], 'period'];
        yield 'kpi target text' => ['kpis', ['employee_id' => 1, 'metric' => 'x', 'period' => '2026-10', 'target' => 'ten'], 'target'];
        yield 'feedback type' => ['feedback', ['to_employee_id' => 1, 'type' => 'rant', 'text' => 'x'], 'type'];
        yield 'feedback no target' => ['feedback', ['type' => 'praise', 'text' => 'x'], 'to_employee_id'];
        yield 'feedback visibility' => ['feedback', ['to_employee_id' => 1, 'type' => 'praise', 'text' => 'x', 'visibility' => 'everyone'], 'visibility'];
        yield 'plan without actions' => ['development-plans', ['employee_id' => 1, 'title' => 'x', 'goals' => []], 'actions'];
        yield 'plan action due format' => ['development-plans', ['employee_id' => 1, 'title' => 'x', 'goals' => [], 'actions' => [['text' => 'a', 'due_on' => '01/12/2026']]], 'actions.0.due_on'];
        yield 'objective 11 key results' => ['objectives', ['scope' => 'personal', 'period' => '2026-Q4', 'title' => 'x', 'visibility' => 'public', 'key_results' => array_fill(0, 11, ['title' => 'k', 'start' => 0, 'target' => 1])], 'key_results'];
        yield 'objective weight over 100' => ['objectives', ['scope' => 'personal', 'period' => '2026-Q4', 'title' => 'x', 'visibility' => 'public', 'key_results' => [['title' => 'k', 'start' => 0, 'target' => 1, 'weight' => 101]]], 'key_results.0.weight'];
        yield 'objective unknown parent' => ['objectives', ['scope' => 'personal', 'period' => '2026-Q4', 'title' => 'x', 'visibility' => 'public', 'parent_objective_id' => 999999, 'key_results' => [['title' => 'k', 'start' => 0, 'target' => 1]]], 'parent_objective_id'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function test_validation(string $path, array $body, string $field): void
    {
        $this->employee([], $user = $this->login(UserRole::Admin));
        $this->actingAs($user)->postJson("/api/perform/$path", $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_check_in_validation_and_unknown_key_result_is_ignored(): void
    {
        ['worker' => $worker] = $this->org();
        $user = $this->userOf($worker);
        $id = $this->actingAs($user)->postJson('/api/perform/objectives', $this->objective())->assertCreated()->assertJsonPath('data.progress', 25)->json('data.id');
        $this->actingAs($user)->postJson("/api/perform/objectives/$id/check-ins", [])->assertUnprocessable()->assertJsonValidationErrors('key_results');
        $this->actingAs($user)->postJson("/api/perform/objectives/$id/check-ins", ['key_results' => [['id' => 'kr1', 'current' => 'three']]])->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/perform/objectives/$id/check-ins", ['key_results' => [['id' => 'kr1', 'current' => '3'], ['id' => 'ghost', 'current' => 100]]])
            ->assertOk()->assertJsonPath('data.progress', 75)->assertJsonCount(1, 'data.key_results');
        // Overshoot is capped at 100 %.
        $this->actingAs($user)->postJson("/api/perform/objectives/$id/check-ins", ['key_results' => [['id' => 'kr1', 'current' => 40]]])->assertOk()->assertJsonPath('data.progress', 100);
    }

    public function test_kpi_with_zero_target_has_no_attainment(): void
    {
        ['lead' => $lead, 'worker' => $worker] = $this->org();
        $this->actingAs($this->userOf($lead))->postJson('/api/perform/kpis', ['employee_id' => $worker->id, 'metric' => 'Incidents', 'period' => '2026-10', 'target' => '0', 'actual' => '2'])
            ->assertCreated()->assertJsonPath('data.attainment', null);
        $this->actingAs($this->userOf($lead))->postJson('/api/perform/kpis', ['employee_id' => $worker->id, 'metric' => 'Calls', 'period' => '2026-10', 'target' => '200'])
            ->assertCreated()->assertJsonPath('data.attainment', null);
    }

    public function test_one_on_one_management_by_the_chain(): void
    {
        ['head' => $head, 'lead' => $lead, 'worker' => $worker] = $this->org();
        $id = $this->actingAs($this->userOf($lead))->postJson('/api/perform/one-on-ones', ['employee_id' => $worker->id, 'scheduled_at' => '2026-10-10 10:00'])->json('data.id');
        // The manager above may reschedule, not read or write the private notes.
        $this->actingAs($this->userOf($head))->patchJson("/api/perform/one-on-ones/$id", ['scheduled_at' => '2026-10-11 09:00'])->assertOk()
            ->assertJsonPath('data.can_private_notes', false);
        $this->actingAs($this->userOf($head))->patchJson("/api/perform/one-on-ones/$id", ['notes_private_manager' => 'x'])->assertForbidden();
        $this->actingAs($this->userOf($lead))->patchJson("/api/perform/one-on-ones/$id", ['status' => 'maybe'])->assertUnprocessable();
        // Admin schedules a pair where the manager equals the employee: refused.
        $this->actingAs($this->login(UserRole::HrManager))->postJson('/api/perform/one-on-ones', [
            'employee_id' => $worker->id, 'manager_employee_id' => $worker->id, 'scheduled_at' => '2026-10-10',
        ])->assertUnprocessable()->assertJsonPath('code', 'self_target');
        $this->actingAs($this->userOf($worker))->deleteJson("/api/perform/one-on-ones/$id")->assertForbidden();
        $this->actingAs($this->userOf($head))->deleteJson("/api/perform/one-on-ones/$id")->assertNoContent();
    }

    public function test_feedback_to_unknown_or_answering_a_non_request(): void
    {
        ['worker' => $worker, 'peer' => $peer] = $this->org();
        $praise = $this->actingAs($this->userOf($worker))->postJson('/api/perform/feedback', ['to_employee_id' => (string) $peer->id, 'type' => 'praise', 'text' => 'Nice'])
            ->assertCreated()->assertJsonPath('data.visibility', 'private_to_recipient')->json('data.id');
        $this->actingAs($this->userOf($peer))->postJson('/api/perform/feedback', ['request_id' => $praise, 'type' => 'praise', 'text' => 'x'])->assertConflict()->assertJsonPath('code', 'request_not_open');
        $this->actingAs($this->userOf($peer))->postJson('/api/perform/feedback', ['request_id' => 999999, 'type' => 'praise', 'text' => 'x'])->assertConflict();
        $this->actingAs($this->userOf($peer))->postJson('/api/perform/feedback', ['to_employee_id' => 999999, 'type' => 'praise', 'text' => 'x'])->assertUnprocessable();
    }
}
