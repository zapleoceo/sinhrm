<?php

declare(strict_types=1);

namespace Tests\Feature\Perform;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Department;
use App\Modules\Perform\Models\ObjectiveCheckin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** OKR: computed progress, check-ins, alignment, visibility, write rights. */
final class ObjectivesTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        return $extra + [
            'scope' => 'personal',
            'period' => '2026-Q4',
            'title' => 'Ship the pilot',
            'visibility' => 'public',
            'key_results' => [
                ['id' => 'kr1', 'title' => 'Clients onboarded', 'start' => 0, 'target' => 10, 'current' => 5, 'weight' => 1],
                ['id' => 'kr2', 'title' => 'Churn', 'start' => 10, 'target' => 5, 'current' => 10, 'weight' => 1],
            ],
        ];
    }

    public function test_progress_is_computed_and_check_ins_keep_history(): void
    {
        ['worker' => $worker] = $this->org();
        $user = $this->userOf($worker);

        $id = $this->actingAs($user)->postJson('/api/perform/objectives', $this->body())
            ->assertCreated()
            ->assertJsonPath('data.owner.id', $worker->id)
            ->assertJsonPath('data.progress', 25) // (0.5 + 0) / 2
            ->json('data.id');

        $this->actingAs($user)->postJson("/api/perform/objectives/{$id}/check-ins", [
            'key_results' => [['id' => 'kr1', 'current' => 10], ['id' => 'kr2', 'current' => 7.5]],
            'comment' => 'Good week',
        ])->assertOk()
            ->assertJsonPath('data.progress', 75) // (1 + 0.5) / 2
            ->assertJsonPath('data.checkins.0.progress_before', 25)
            ->assertJsonPath('data.checkins.0.progress_after', 75)
            ->assertJsonPath('data.checkins.0.comment', 'Good week');
        $this->assertSame(1, ObjectiveCheckin::query()->count());
    }

    public function test_alignment_tree_rejects_loops(): void
    {
        ['lead' => $lead, 'worker' => $worker] = $this->org();
        $parent = $this->actingAs($this->userOf($lead))->postJson('/api/perform/objectives', $this->body(['title' => 'Team goal']))->json('data.id');
        $child = $this->actingAs($this->userOf($worker))->postJson('/api/perform/objectives', $this->body(['parent_objective_id' => $parent]))
            ->assertCreated()->assertJsonPath('data.parent_objective_id', $parent)->json('data.id');

        // The lead (above the worker) may edit both; aligning the parent under its own child is a loop.
        $this->actingAs($this->userOf($lead))->putJson("/api/perform/objectives/{$parent}", $this->body(['parent_objective_id' => $child]))
            ->assertUnprocessable()->assertJsonPath('code', 'alignment_cycle');
        $this->actingAs($this->userOf($lead))->putJson("/api/perform/objectives/{$parent}", $this->body(['parent_objective_id' => $parent]))
            ->assertUnprocessable()->assertJsonPath('code', 'alignment_cycle');
    }

    public function test_visibility_and_write_rights(): void
    {
        $sales = Department::factory()->create();
        ['lead' => $lead, 'worker' => $worker, 'peer' => $peer, 'other' => $other] = $this->org();
        $worker->update(['department_id' => $sales->id]);
        $other->update(['department_id' => $sales->id]);

        $private = $this->actingAs($this->userOf($worker))->postJson('/api/perform/objectives', $this->body(['visibility' => 'private', 'title' => 'Private one']))->json('data.id');
        $team = $this->actingAs($this->userOf($worker))->postJson('/api/perform/objectives', $this->body(['visibility' => 'team', 'title' => 'Team one']))->json('data.id');

        // Private: owner, managers above, admin.
        $this->actingAs($this->userOf($lead))->getJson("/api/perform/objectives/{$private}")->assertOk()->assertJsonPath('data.can_edit', true);
        $this->actingAs($this->login(UserRole::Admin))->getJson("/api/perform/objectives/{$private}")->assertOk();
        $this->actingAs($this->userOf($peer))->getJson("/api/perform/objectives/{$private}")->assertNotFound();
        // Team: the owner's department (other is in it, peer is not).
        $this->actingAs($this->userOf($other))->getJson("/api/perform/objectives/{$team}")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->actingAs($this->userOf($peer))->getJson("/api/perform/objectives/{$team}")->assertNotFound();
        $listed = $this->actingAs($this->userOf($peer))->getJson('/api/perform/objectives?period=2026-Q4')->assertOk()->json('data');
        $this->assertSame([], $listed);

        // Writing: not for colleagues; company goals and goals for others — admins only.
        $this->actingAs($this->userOf($other))->putJson("/api/perform/objectives/{$team}", $this->body())->assertForbidden();
        $this->actingAs($this->userOf($other))->postJson("/api/perform/objectives/{$team}/check-ins", ['key_results' => [['id' => 'kr1', 'current' => 1]]])->assertForbidden();
        $this->actingAs($this->userOf($worker))->postJson('/api/perform/objectives', $this->body(['scope' => 'company']))->assertForbidden();
        $this->actingAs($this->userOf($worker))->postJson('/api/perform/objectives', $this->body(['owner_employee_id' => $peer->id]))->assertForbidden();
        $this->actingAs($this->userOf($lead))->postJson('/api/perform/objectives', $this->body(['owner_employee_id' => $peer->id]))->assertCreated();
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/perform/objectives', $this->body(['scope' => 'company', 'owner_employee_id' => null]))
            ->assertCreated()->assertJsonPath('data.owner', null);
        $this->actingAs($this->userOf($lead))->deleteJson("/api/perform/objectives/{$private}")->assertNoContent();
    }

    public function test_validation(): void
    {
        ['worker' => $worker] = $this->org();
        $this->actingAs($this->userOf($worker))->postJson('/api/perform/objectives', $this->body(['period' => '2026-13', 'key_results' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors(['period', 'key_results']);
    }
}
