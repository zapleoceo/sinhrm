<?php

declare(strict_types=1);

namespace Tests\Feature\Perform;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Models\Employee;
use App\Modules\Perform\Models\ReviewAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Perform review setup + 360 results — perform-manage per role, 404/409/422 on scales, competencies, cycles and
 * templates, cycle state machine, upward group anonymity (≥ 3), non-anonymous attribution, submit after close.
 */
final class ReviewSetupRoutesTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private User $admin;

    private int $scale;

    /** @var list<int> */
    private array $competencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->login(UserRole::Admin);
        $this->scale = (int) $this->actingAs($this->admin)->postJson('/api/perform/review/scales', [
            'name' => 'Three', 'levels' => [['value' => '1', 'label' => 'Low'], ['value' => 2, 'label' => 'Mid'], ['value' => 3, 'label' => 'High']],
        ])->assertCreated()->json('data.id');
        $this->competencies = [(int) $this->actingAs($this->admin)->postJson('/api/perform/review/competencies', ['name' => 'Focus', 'scale_id' => (string) $this->scale])
            ->assertCreated()->json('data.id')];
    }

    /**
     * @param  list<string>  $types
     * @param  array<string, mixed>  $extra
     */
    private function draftCycle(array $types = ['self'], array $extra = []): int
    {
        return (int) $this->actingAs($this->admin)->postJson('/api/perform/review/cycles', $extra + [
            'name' => 'Cycle', 'period_start' => '2026-07-01', 'period_end' => '2026-12-31', 'participants' => [],
            'types' => $types, 'competency_ids' => $this->competencies,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  list<string>  $types
     * @param  array<string, mixed>  $extra
     */
    private function activeCycle(array $types, array $extra = []): int
    {
        $id = $this->draftCycle($types, $extra);
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/$id/activate")->assertOk();

        return $id;
    }

    private function submit(int $cycle, Employee $subject, Employee $reviewer, string $type, int $rating, ?string $comment = null): void
    {
        $a = ReviewAssignment::query()->where(['cycle_id' => $cycle, 'subject_employee_id' => $subject->id, 'reviewer_employee_id' => $reviewer->id, 'type' => $type])->firstOrFail();
        $this->actingAs($this->userOf($reviewer))->postJson("/api/perform/review/assignments/{$a->id}/submit", ['answers' => [
            ['competency_id' => (string) $this->competencies[0], 'rating' => (string) $rating, 'comment' => $comment],
        ]])->assertOk();
    }

    /** @return iterable<string, array{string, string}> */
    public static function adminRoutes(): iterable
    {
        yield 'templates.store' => ['POST', '/api/perform/one-on-one-templates'];
        yield 'templates.update' => ['PUT', '/api/perform/one-on-one-templates/1'];
        yield 'templates.destroy' => ['DELETE', '/api/perform/one-on-one-templates/1'];
        yield 'scales' => ['GET', '/api/perform/review/scales'];
        yield 'scales.store' => ['POST', '/api/perform/review/scales'];
        yield 'scales.update' => ['PUT', '/api/perform/review/scales/1'];
        yield 'scales.destroy' => ['DELETE', '/api/perform/review/scales/1'];
        yield 'competencies' => ['GET', '/api/perform/review/competencies'];
        yield 'competencies.store' => ['POST', '/api/perform/review/competencies'];
        yield 'competencies.update' => ['PUT', '/api/perform/review/competencies/1'];
        yield 'competencies.destroy' => ['DELETE', '/api/perform/review/competencies/1'];
        yield 'cycles' => ['GET', '/api/perform/review/cycles'];
        yield 'cycles.store' => ['POST', '/api/perform/review/cycles'];
        yield 'cycles.show' => ['GET', '/api/perform/review/cycles/1'];
        yield 'cycles.update' => ['PUT', '/api/perform/review/cycles/1'];
        yield 'cycles.destroy' => ['DELETE', '/api/perform/review/cycles/1'];
        yield 'cycles.activate' => ['POST', '/api/perform/review/cycles/1/activate'];
        yield 'cycles.close' => ['POST', '/api/perform/review/cycles/1/close'];
        yield 'cycles.assign' => ['POST', '/api/perform/review/cycles/1/assignments'];
    }

    #[DataProvider('adminRoutes')]
    public function test_setup_routes_need_login(string $method, string $uri): void
    {
        $this->app['auth']->forgetGuards();
        $this->json($method, $uri)->assertUnauthorized();
    }

    /** @return iterable<string, array{UserRole}> */
    public static function nonHrRoles(): iterable
    {
        yield 'recruiter' => [UserRole::Recruiter];
        yield 'employee' => [UserRole::Employee];
        yield 'viewer' => [UserRole::Viewer];
    }

    #[DataProvider('nonHrRoles')]
    public function test_setup_routes_are_forbidden_for_non_hr(UserRole $role): void
    {
        $user = $this->login($role);
        foreach (self::adminRoutes() as [$method, $uri]) {
            $this->actingAs($user)->json($method, $uri)->assertForbidden();
        }
    }

    /** @return iterable<string, array{UserRole}> */
    public static function hrRoles(): iterable
    {
        yield 'superadmin' => [UserRole::Superadmin];
        yield 'admin' => [UserRole::Admin];
        yield 'hr_manager' => [UserRole::HrManager];
    }

    #[DataProvider('hrRoles')]
    public function test_hr_roles_run_a_whole_cycle(UserRole $role): void
    {
        $hr = $this->login($role);
        $this->employee([], $this->login(UserRole::Employee));
        $id = $this->actingAs($hr)->postJson('/api/perform/review/cycles', [
            'name' => 'By '.$role->value, 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'participants' => [],
            'types' => ['self'], 'competency_ids' => $this->competencies,
        ])->assertCreated()->json('data.id');
        $this->actingAs($hr)->putJson("/api/perform/review/cycles/$id", [
            'name' => 'Renamed', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'participants' => [],
            'types' => ['self'], 'competency_ids' => $this->competencies,
        ])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->actingAs($hr)->postJson("/api/perform/review/cycles/$id/activate")->assertOk()->assertJsonPath('data.progress.total', 1);
        $this->actingAs($hr)->postJson("/api/perform/review/cycles/$id/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAs($hr)->getJson('/api/perform/review/cycles')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unknown_ids_are_404(): void
    {
        ['worker' => $worker, 'peer' => $peer] = $this->org();
        $scale = ['name' => 'x', 'levels' => [['value' => 1, 'label' => 'a'], ['value' => 2, 'label' => 'b']]];
        $competency = ['name' => 'x', 'scale_id' => $this->scale];
        $cycle = ['name' => 'x', 'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'participants' => [], 'types' => ['self'], 'competency_ids' => $this->competencies];
        $calls = [
            ['PUT', '/api/perform/review/scales/999999', $scale], ['DELETE', '/api/perform/review/scales/999999', []],
            ['PUT', '/api/perform/review/competencies/999999', $competency], ['DELETE', '/api/perform/review/competencies/999999', []],
            ['GET', '/api/perform/review/cycles/999999', []], ['PUT', '/api/perform/review/cycles/999999', $cycle],
            ['DELETE', '/api/perform/review/cycles/999999', []], ['POST', '/api/perform/review/cycles/999999/activate', []],
            ['POST', '/api/perform/review/cycles/999999/close', []],
            ['POST', '/api/perform/review/cycles/999999/assignments', ['subject_employee_id' => $worker->id, 'reviewer_employee_id' => $peer->id, 'type' => 'peer']],
            ['PUT', '/api/perform/one-on-one-templates/999999', ['name' => 'x', 'agenda' => []]], ['DELETE', '/api/perform/one-on-one-templates/999999', []],
            ['GET', '/api/perform/review/assignments/999999', []],
            ['POST', '/api/perform/review/assignments/999999/submit', ['answers' => [['competency_id' => $this->competencies[0], 'rating' => 1]]]],
        ];
        foreach ($calls as [$method, $uri, $body]) {
            $this->actingAs($this->admin)->json($method, $uri, $body)->assertNotFound();
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function invalidSetup(): iterable
    {
        yield 'scale with one level' => ['scales', ['name' => 'x', 'levels' => [['value' => 1, 'label' => 'a']]], 'levels'];
        yield 'scale duplicate values' => ['scales', ['name' => 'x', 'levels' => [['value' => 1, 'label' => 'a'], ['value' => 1, 'label' => 'b']]], 'levels.0.value'];
        yield 'scale value over 100' => ['scales', ['name' => 'x', 'levels' => [['value' => 1, 'label' => 'a'], ['value' => 101, 'label' => 'b']]], 'levels.1.value'];
        yield 'competency unknown scale' => ['competencies', ['name' => 'x', 'scale_id' => 999999], 'scale_id'];
        yield 'cycle end before start' => ['cycles', ['name' => 'x', 'period_start' => '2026-12-31', 'period_end' => '2026-01-01', 'participants' => [], 'types' => ['self'], 'competency_ids' => [1]], 'period_end'];
        yield 'cycle unknown type' => ['cycles', ['name' => 'x', 'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'participants' => [], 'types' => ['boss'], 'competency_ids' => [1]], 'types.0'];
        yield 'cycle duplicate types' => ['cycles', ['name' => 'x', 'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'participants' => [], 'types' => ['self', 'self'], 'competency_ids' => [1]], 'types.0'];
        yield 'cycle no competencies' => ['cycles', ['name' => 'x', 'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'participants' => [], 'types' => ['self'], 'competency_ids' => []], 'competency_ids'];
        yield 'cycle unknown branch' => ['cycles', ['name' => 'x', 'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'participants' => ['branch_ids' => [999999]], 'types' => ['self'], 'competency_ids' => [1]], 'participants.branch_ids.0'];
        yield 'cycle deadline format' => ['cycles', ['name' => 'x', 'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'participants' => [], 'types' => ['self'], 'competency_ids' => [1], 'deadlines' => ['self' => '15.01.2027']], 'deadlines.self'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidSetup')]
    public function test_setup_validation(string $path, array $body, string $field): void
    {
        $this->actingAs($this->admin)->postJson("/api/perform/review/$path", $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_cycle_state_machine(): void
    {
        $draft = $this->draftCycle();
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/$draft/close")->assertConflict()->assertJsonPath('code', 'cycle_not_active');
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/$draft/assignments", [])->assertUnprocessable();
        ['worker' => $worker, 'peer' => $peer] = $this->org();
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/$draft/assignments", [
            'subject_employee_id' => $worker->id, 'reviewer_employee_id' => $peer->id, 'type' => 'peer',
        ])->assertConflict()->assertJsonPath('code', 'cycle_not_active');
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/$draft/activate")->assertOk();

        $this->actingAs($this->admin)->putJson("/api/perform/review/cycles/$draft", [
            'name' => 'x', 'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'participants' => [], 'types' => ['self'], 'competency_ids' => $this->competencies,
        ])->assertConflict()->assertJsonPath('code', 'cycle_not_draft');
        $this->actingAs($this->admin)->deleteJson("/api/perform/review/cycles/$draft")->assertConflict()->assertJsonPath('code', 'cycle_not_draft');
        $this->actingAs($this->admin)->deleteJson("/api/perform/review/competencies/{$this->competencies[0]}")->assertConflict()->assertJsonPath('code', 'in_use');

        // A pending form cannot be submitted after the cycle closes: final results never change afterwards.
        $pending = ReviewAssignment::query()->where('cycle_id', $draft)->where('reviewer_employee_id', $worker->id)->firstOrFail();
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/$draft/close")->assertOk();
        $this->actingAs($this->userOf($worker))->postJson("/api/perform/review/assignments/{$pending->id}/submit", ['answers' => [
            ['competency_id' => $this->competencies[0], 'rating' => 2],
        ]])->assertConflict()->assertJsonPath('code', 'cycle_not_active');
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/$draft/close")->assertConflict();

        // A draft cycle is deletable; its results are not a thing.
        $other = $this->draftCycle();
        $this->actingAs($this->admin)->getJson("/api/perform/review/cycles/$other/results/{$worker->id}")->assertNotFound();
        $this->actingAs($this->admin)->deleteJson("/api/perform/review/cycles/$other")->assertNoContent();
    }

    public function test_submit_validation(): void
    {
        ['worker' => $worker] = $this->org();
        $cycle = $this->activeCycle(['self']);
        $a = ReviewAssignment::query()->where(['cycle_id' => $cycle, 'reviewer_employee_id' => $worker->id])->firstOrFail();
        $user = $this->userOf($worker);
        $this->actingAs($user)->postJson("/api/perform/review/assignments/{$a->id}/submit", [])->assertUnprocessable()->assertJsonValidationErrors('answers');
        $this->actingAs($user)->postJson("/api/perform/review/assignments/{$a->id}/submit", ['answers' => [['competency_id' => $this->competencies[0], 'rating' => 'high']]])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.0.rating');
        $this->actingAs($user)->postJson("/api/perform/review/assignments/{$a->id}/submit", ['answers' => [
            ['competency_id' => $this->competencies[0], 'rating' => 2], ['competency_id' => $this->competencies[0], 'rating' => 3],
        ]])->assertUnprocessable()->assertJsonPath('code', 'invalid_answers');
        $this->actingAs($user)->postJson("/api/perform/review/assignments/{$a->id}/submit", ['answers' => [['competency_id' => 999999, 'rating' => 2]]])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_answers');
    }

    public function test_upward_group_of_two_stays_hidden_after_close(): void
    {
        ['head' => $head, 'lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org();
        $cycle = $this->activeCycle(['manager', 'upward']);
        $this->submit($cycle, $lead, $head, 'manager', 3, 'Manager view');
        $this->submit($cycle, $lead, $worker, 'upward', 1, 'Upward one');
        $this->submit($cycle, $lead, $peer, 'upward', 3, 'Upward two');
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/$cycle/close")->assertOk();

        $res = $this->actingAs($this->admin)->getJson("/api/perform/review/cycles/$cycle/results/{$lead->id}")->assertOk()
            ->assertJsonPath('data.groups.upward', ['reviewers' => null, 'suppressed' => true])
            ->assertJsonPath('data.competencies.0.scores.upward', null)
            ->assertJsonPath('data.competencies.0.scores.manager', 3)
            ->assertJsonPath('data.competencies.0.average', 3);
        $body = (string) $res->getContent();
        $this->assertStringNotContainsString('Upward', $body, 'no comments of a suppressed group');
        $this->assertStringContainsString('Manager view', $body);
        // The subject (lead) sees the same after closing; the average does not leak the hidden ratings.
        $this->actingAs($this->userOf($lead))->getJson("/api/perform/review/cycles/$cycle/results/{$lead->id}")->assertOk()
            ->assertJsonPath('data.competencies.0.average', 3);
    }

    public function test_upward_group_of_three_is_shown_without_names(): void
    {
        ['head' => $head, 'lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org();
        $third = $this->employee(['full_name' => 'Third Report', 'manager_id' => $lead->id], $this->login());
        $cycle = $this->activeCycle(['upward']);
        foreach ([[$worker, 1], [$peer, 2], [$third, 3]] as [$reviewer, $rating]) {
            $this->submit($cycle, $lead, $reviewer, 'upward', $rating, 'Upward by rating '.$rating);
        }
        // Active: only a completion range even for the head above.
        $this->actingAs($this->userOf($head))->getJson("/api/perform/review/cycles/$cycle/results/{$lead->id}")->assertOk()
            ->assertJsonPath('data.groups.upward.submitted', '3–4')->assertJsonPath('data.competencies.0.scores.upward', null);
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/$cycle/close")->assertOk();

        $res = $this->actingAs($this->userOf($head))->getJson("/api/perform/review/cycles/$cycle/results/{$lead->id}")->assertOk()
            ->assertJsonPath('data.groups.upward', ['reviewers' => 3, 'suppressed' => false])
            ->assertJsonPath('data.competencies.0.scores.upward', 2);
        $this->assertSame([null, null, null], array_column((array) $res->json('data.comments'), 'author'));
        foreach ([$worker, $peer, $third] as $reviewer) {
            $this->assertStringNotContainsString($reviewer->full_name, (string) $res->getContent());
        }
        // Comments are sorted by text, not by submission order.
        $this->assertSame(['Upward by rating 1', 'Upward by rating 2', 'Upward by rating 3'], array_column((array) $res->json('data.comments'), 'text'));
    }

    public function test_non_anonymous_cycle_attributes_peer_comments(): void
    {
        ['lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org();
        $p2 = $this->employee(['full_name' => 'Peer Two', 'manager_id' => $lead->id], $this->login());
        $p3 = $this->employee(['full_name' => 'Peer Three', 'manager_id' => $lead->id], $this->login());
        $cycle = $this->activeCycle(['peer'], ['anonymous' => false]);
        foreach ([$peer, $p2, $p3] as $p) {
            $this->submit($cycle, $worker, $p, 'peer', 2, 'Note from '.$p->full_name);
        }
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/$cycle/close")->assertOk();
        $authors = array_column((array) $this->actingAs($this->admin)->getJson("/api/perform/review/cycles/$cycle/results/{$worker->id}")->assertOk()
            ->assertJsonPath('data.cycle.anonymous', false)->json('data.comments'), 'author');
        $this->assertEqualsCanonicalizing(['Peer Person', 'Peer Two', 'Peer Three'], $authors);
    }

    public function test_employee_results_route_hides_foreign_and_open_cycles(): void
    {
        ['lead' => $lead, 'worker' => $worker, 'other' => $other] = $this->org();
        $cycle = $this->activeCycle(['self']);
        $this->submit($cycle, $worker, $worker, 'self', 3);
        $this->actingAs($this->userOf($worker))->getJson("/api/perform/review/employees/{$worker->id}/results")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->userOf($lead))->getJson("/api/perform/review/employees/{$worker->id}/results")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.competencies.0.scores.self', 3);
        $this->actingAs($this->userOf($other))->getJson("/api/perform/review/cycles/$cycle/results/{$worker->id}")->assertNotFound();
        $this->actingAs($this->admin)->getJson("/api/perform/review/cycles/999999/results/{$worker->id}")->assertNotFound();
    }

    public function test_templates_crud_and_validation(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/perform/one-on-one-templates', ['name' => 'Weekly', 'agenda' => ['Wins', 'Blockers']])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->putJson("/api/perform/one-on-one-templates/$id", ['name' => 'Weekly v2', 'agenda' => ['Wins']])->assertOk()
            ->assertJsonPath('data.name', 'Weekly v2');
        $this->actingAs($this->admin)->postJson('/api/perform/one-on-one-templates', ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('agenda');
        $this->actingAs($this->admin)->postJson('/api/perform/one-on-one-templates', ['name' => 'x', 'agenda' => [str_repeat('a', 501)]])->assertUnprocessable();
        $this->actingAs($this->login(UserRole::Employee))->getJson('/api/perform/one-on-one-templates')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->deleteJson("/api/perform/one-on-one-templates/$id")->assertNoContent();
    }
}
