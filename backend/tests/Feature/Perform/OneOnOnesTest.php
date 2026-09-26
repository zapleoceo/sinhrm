<?php

declare(strict_types=1);

namespace Tests\Feature\Perform;

use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** 1:1s: who schedules, who sees, per-field edit rights, private notes never leave the meeting's manager. */
final class OneOnOnesTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_manager_schedules_with_a_report_and_private_notes_stay_with_the_manager(): void
    {
        ['head' => $head, 'lead' => $lead, 'worker' => $worker, 'peer' => $peer, 'other' => $other] = $this->org();
        $admin = $this->login(UserRole::Admin);
        $template = $this->actingAs($admin)->postJson('/api/perform/one-on-one-templates', ['name' => 'Career', 'agenda' => ['Goals', 'Blockers']])
            ->assertCreated()->json('data.id');

        $id = $this->actingAs($this->userOf($lead))->postJson('/api/perform/one-on-ones', [
            'employee_id' => $worker->id, 'scheduled_at' => '2026-10-10 10:00:00', 'template_id' => $template,
        ])->assertCreated()
            ->assertJsonPath('data.manager.id', $lead->id)
            ->assertJsonPath('data.agenda.0.text', 'Goals')
            ->assertJsonPath('data.can_private_notes', true)
            ->json('data.id');

        $this->actingAs($this->userOf($lead))->patchJson("/api/perform/one-on-ones/{$id}", ['notes_private_manager' => 'Synthetic private note'])
            ->assertOk()->assertJsonPath('data.notes_private_manager', 'Synthetic private note');

        // The employee: sees the meeting, never the private notes (the key is absent, not null).
        $seen = $this->actingAs($this->userOf($worker))->getJson("/api/perform/one-on-ones/{$id}")->assertOk();
        $this->assertArrayNotHasKey('notes_private_manager', (array) $seen->json('data'));
        $this->assertStringNotContainsString('Synthetic private note', (string) $seen->getContent());
        // Nor the admin, nor the manager's manager.
        foreach ([$admin, $this->userOf($head)] as $viewer) {
            $body = (string) $this->actingAs($viewer)->getJson('/api/perform/one-on-ones')->assertOk()->getContent();
            $this->assertStringContainsString('"id":'.$id, $body);
            $this->assertStringNotContainsString('Synthetic private note', $body);
        }
        // Unrelated people: 404, and not listed.
        foreach ([$peer, $other] as $stranger) {
            $this->actingAs($this->userOf($stranger))->getJson("/api/perform/one-on-ones/{$id}")->assertNotFound();
            $this->actingAs($this->userOf($stranger))->getJson('/api/perform/one-on-ones')->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_employee_edits_shared_parts_only(): void
    {
        ['lead' => $lead, 'worker' => $worker] = $this->org();
        $id = $this->actingAs($this->userOf($lead))->postJson('/api/perform/one-on-ones', [
            'employee_id' => $worker->id, 'scheduled_at' => '2026-10-10 10:00:00', 'agenda' => [['text' => 'Workload']],
        ])->assertCreated()->json('data.id');
        $employee = $this->userOf($worker);

        $this->actingAs($employee)->patchJson("/api/perform/one-on-ones/{$id}", [
            'notes_shared' => 'Agreed next steps',
            'action_items' => [['text' => 'Prepare demo', 'due_on' => '2026-10-20']],
        ])->assertOk()
            ->assertJsonPath('data.notes_shared', 'Agreed next steps')
            ->assertJsonPath('data.action_items.0.text', 'Prepare demo')
            ->assertJsonPath('data.action_items.0.done', false)
            ->assertJsonPath('data.can_manage', false);

        $this->actingAs($employee)->patchJson("/api/perform/one-on-ones/{$id}", ['notes_private_manager' => 'x'])->assertForbidden();
        $this->actingAs($employee)->patchJson("/api/perform/one-on-ones/{$id}", ['status' => 'cancelled'])->assertForbidden();
        $this->actingAs($employee)->deleteJson("/api/perform/one-on-ones/{$id}")->assertForbidden();
        $this->actingAs($this->userOf($lead))->patchJson("/api/perform/one-on-ones/{$id}", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_only_managers_above_or_admins_schedule(): void
    {
        $this->getJson('/api/perform/one-on-ones')->assertUnauthorized();
        ['lead' => $lead, 'worker' => $worker, 'peer' => $peer, 'other' => $other] = $this->org();

        $this->actingAs($this->userOf($peer))->postJson('/api/perform/one-on-ones', ['employee_id' => $worker->id, 'scheduled_at' => '2026-10-10'])
            ->assertForbidden();
        $this->actingAs($this->userOf($worker))->postJson('/api/perform/one-on-ones', ['employee_id' => $lead->id, 'scheduled_at' => '2026-10-10'])
            ->assertForbidden();
        $this->actingAs($this->userOf($lead))->postJson('/api/perform/one-on-ones', ['employee_id' => $lead->id, 'scheduled_at' => '2026-10-10'])
            ->assertUnprocessable()->assertJsonPath('code', 'self_target');
        // Admin: any pair.
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/perform/one-on-ones', [
            'employee_id' => $other->id, 'manager_employee_id' => $lead->id, 'scheduled_at' => '2026-10-10',
        ])->assertCreated();
        // Template writes are admin-only.
        $this->actingAs($this->userOf($lead))->postJson('/api/perform/one-on-one-templates', ['name' => 'X', 'agenda' => []])->assertForbidden();
        $this->actingAs($this->userOf($lead))->getJson('/api/perform/one-on-one-templates')->assertOk();
    }
}
