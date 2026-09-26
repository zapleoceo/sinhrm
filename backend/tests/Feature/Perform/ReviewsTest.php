<?php

declare(strict_types=1);

namespace Tests\Feature\Perform;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Models\Employee;
use App\Modules\Perform\Models\ReviewAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Review cycles: admin setup, assignment generation, the reviewer form, results — peer group suppressed below three
 * reviewers, peer names never in any answer of an anonymous cycle, the subject sees results only after closing.
 */
final class ReviewsTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private User $admin;

    /** @var array{head: Employee, lead: Employee, worker: Employee, peer: Employee, other: Employee} */
    private array $org;

    /** @var list<int> */
    private array $competencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->login(UserRole::Admin);
        $this->org = $this->org();
        $scale = $this->actingAs($this->admin)->postJson('/api/perform/review/scales', [
            'name' => 'Five levels',
            'levels' => [['value' => 5, 'label' => 'Very strong'], ['value' => 1, 'label' => 'Very weak'], ['value' => 3, 'label' => 'OK']],
        ])->assertCreated()->assertJsonPath('data.levels.0.value', 1)->json('data.id');
        $this->competencies = [
            (int) $this->actingAs($this->admin)->postJson('/api/perform/review/competencies', ['name' => 'Communication', 'scale_id' => $scale])->assertCreated()->json('data.id'),
            (int) $this->actingAs($this->admin)->postJson('/api/perform/review/competencies', ['name' => 'Ownership', 'scale_id' => $scale])->assertCreated()->json('data.id'),
        ];
    }

    /** @param  list<string>  $types */
    private function cycle(array $types, bool $anonymous = true): int
    {
        $id = $this->actingAs($this->admin)->postJson('/api/perform/review/cycles', [
            'name' => '2026 H2', 'period_start' => '2026-07-01', 'period_end' => '2026-12-31', 'participants' => [],
            'types' => $types, 'competency_ids' => $this->competencies, 'anonymous' => $anonymous,
            'deadlines' => ['self' => '2027-01-15'],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'active');

        return (int) $id;
    }

    private function assignment(int $cycle, Employee $subject, Employee $reviewer, string $type): int
    {
        $a = ReviewAssignment::query()->where(['cycle_id' => $cycle, 'subject_employee_id' => $subject->id, 'reviewer_employee_id' => $reviewer->id, 'type' => $type])->first();
        $this->assertNotNull($a, "assignment {$type} {$reviewer->full_name} → {$subject->full_name}");

        return $a->id;
    }

    private function submit(int $assignment, Employee $reviewer, int $rating, ?string $comment = null): void
    {
        $this->actingAs($this->userOf($reviewer))->postJson("/api/perform/review/assignments/{$assignment}/submit", ['answers' => [
            ['competency_id' => $this->competencies[0], 'rating' => $rating, 'comment' => $comment],
            ['competency_id' => $this->competencies[1], 'rating' => $rating],
        ]])->assertOk()->assertJsonPath('data.status', 'submitted');
    }

    public function test_setup_is_admin_only(): void
    {
        $user = $this->userOf($this->org['lead']);
        $this->actingAs($user)->getJson('/api/perform/review/cycles')->assertForbidden();
        $this->actingAs($user)->postJson('/api/perform/review/scales', ['name' => 'x', 'levels' => []])->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/perform/review/competencies')->assertOk()->assertJsonCount(2, 'data');
        // A scale used by a competency cannot be deleted.
        $scale = $this->actingAs($this->admin)->getJson('/api/perform/review/scales')->json('data.0.id');
        $this->actingAs($this->admin)->deleteJson("/api/perform/review/scales/{$scale}")->assertConflict()->assertJsonPath('code', 'in_use');
    }

    public function test_activation_creates_assignments_per_type(): void
    {
        ['head' => $head, 'lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org;
        $cycle = $this->cycle(['self', 'manager', 'peer', 'upward']);

        $this->assignment($cycle, $worker, $worker, 'self');
        $this->assignment($cycle, $worker, $lead, 'manager');
        $this->assignment($cycle, $worker, $peer, 'peer');
        $this->assignment($cycle, $lead, $worker, 'upward');
        $this->assignment($cycle, $lead, $head, 'manager');
        // 5 people self (5) + manager for lead/worker/peer (3) + peers worker↔peer (2) + upward: head←lead, lead←worker/peer (3).
        $this->assertSame(13, ReviewAssignment::query()->where('cycle_id', $cycle)->count());
        // Activation is one-off; draft-only edits.
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/{$cycle}/activate")->assertConflict();
        $this->actingAs($this->admin)->getJson("/api/perform/review/cycles/{$cycle}")->assertOk()->assertJsonPath('data.progress.total', 13);
    }

    public function test_reviewer_form_and_validation(): void
    {
        ['worker' => $worker, 'peer' => $peer] = $this->org;
        $cycle = $this->cycle(['self', 'peer']);
        $own = $this->assignment($cycle, $worker, $worker, 'self');

        // Own self-review plus the peer review of the colleague.
        $this->actingAs($this->userOf($worker))->getJson('/api/perform/review/assignments')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->userOf($worker))->getJson("/api/perform/review/assignments/{$own}")->assertOk()
            ->assertJsonCount(2, 'data.competencies')->assertJsonPath('data.cycle.deadline', '2027-01-15');
        // Someone else's form does not exist for you.
        $this->actingAs($this->userOf($peer))->getJson("/api/perform/review/assignments/{$own}")->assertNotFound();
        // Not on the scale / missing competency.
        $this->actingAs($this->userOf($worker))->postJson("/api/perform/review/assignments/{$own}/submit", ['answers' => [
            ['competency_id' => $this->competencies[0], 'rating' => 4], ['competency_id' => $this->competencies[1], 'rating' => 3],
        ]])->assertUnprocessable()->assertJsonPath('code', 'invalid_answers');
        $this->actingAs($this->userOf($worker))->postJson("/api/perform/review/assignments/{$own}/submit", ['answers' => [
            ['competency_id' => $this->competencies[0], 'rating' => 3],
        ]])->assertUnprocessable()->assertJsonPath('code', 'invalid_answers');
        $this->submit($own, $worker, 3);
        $this->actingAs($this->userOf($worker))->postJson("/api/perform/review/assignments/{$own}/submit", ['answers' => [
            ['competency_id' => $this->competencies[0], 'rating' => 3], ['competency_id' => $this->competencies[1], 'rating' => 3],
        ]])->assertConflict()->assertJsonPath('code', 'already_submitted');
    }

    public function test_peer_results_are_suppressed_below_three_and_never_name_reviewers(): void
    {
        ['head' => $head, 'lead' => $lead, 'worker' => $worker, 'peer' => $peer] = $this->org;
        $p2 = $this->employee(['full_name' => 'Second Peer', 'manager_id' => $lead->id], $this->login());
        $p3 = $this->employee(['full_name' => 'Third Peer', 'manager_id' => $lead->id], $this->login());
        $cycle = $this->cycle(['self', 'manager', 'peer']);

        $this->submit($this->assignment($cycle, $worker, $worker, 'self'), $worker, 5);
        $this->submit($this->assignment($cycle, $worker, $lead, 'manager'), $lead, 3, 'Solid quarter');
        $this->submit($this->assignment($cycle, $worker, $peer, 'peer'), $peer, 1, 'Peer comment one');
        $this->submit($this->assignment($cycle, $worker, $p2, 'peer'), $p2, 3, 'Peer comment two');

        $url = "/api/perform/review/cycles/{$cycle}/results/{$worker->id}";
        $two = $this->actingAs($this->userOf($lead))->getJson($url)->assertOk()
            ->assertJsonPath('data.groups.peer', ['reviewers' => null, 'suppressed' => true])
            ->assertJsonPath('data.competencies.0.scores.peer', null)
            ->assertJsonPath('data.competencies.0.scores.self', 5)
            ->assertJsonPath('data.competencies.0.scores.manager', 3);
        $this->assertStringNotContainsString('Peer comment', (string) $two->getContent());

        $this->submit($this->assignment($cycle, $worker, $p3, 'peer'), $p3, 5);
        $three = $this->actingAs($this->userOf($head))->getJson($url)->assertOk()
            ->assertJsonPath('data.groups.peer', ['reviewers' => 3, 'suppressed' => false])
            ->assertJsonPath('data.competencies.0.scores.peer', 3)
            ->assertJsonPath('data.competencies.0.average', 3);
        $body = (string) $three->getContent();
        $this->assertStringContainsString('Peer comment one', $body);
        foreach ([$peer, $p2, $p3] as $reviewer) {
            $this->assertStringNotContainsString($reviewer->full_name, $body, 'peer reviewer must stay anonymous');
            $this->assertStringNotContainsString('"reviewer_id":'.$reviewer->id, $body);
        }
        // The manager's comment is attributed; peers' are not.
        $comments = collect((array) $three->json('data.comments'));
        $this->assertSame([null, null], $comments->where('type', 'peer')->pluck('author')->all());
        $this->assertSame([$lead->full_name], $comments->where('type', 'manager')->pluck('author')->all());

        // The subject: only after the cycle is closed; strangers never.
        $this->actingAs($this->userOf($worker))->getJson($url)->assertNotFound();
        $this->actingAs($this->userOf($peer))->getJson($url)->assertNotFound();
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/{$cycle}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAs($this->userOf($worker))->getJson($url)->assertOk();
        $this->actingAs($this->userOf($worker))->getJson("/api/perform/review/employees/{$worker->id}/results")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->userOf($peer))->getJson("/api/perform/review/employees/{$worker->id}/results")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_adds_a_peer_by_hand(): void
    {
        ['worker' => $worker, 'other' => $other] = $this->org;
        $cycle = $this->cycle(['peer']);
        $body = ['subject_employee_id' => $worker->id, 'reviewer_employee_id' => $other->id, 'type' => 'peer'];
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/{$cycle}/assignments", $body)->assertCreated();
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/{$cycle}/assignments", $body)->assertConflict()->assertJsonPath('code', 'duplicate');
        $this->actingAs($this->admin)->postJson("/api/perform/review/cycles/{$cycle}/assignments", ['reviewer_employee_id' => $worker->id] + $body)
            ->assertUnprocessable()->assertJsonPath('code', 'self_target');
    }
}
