<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\RejectReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class CandidateBulkTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    public function test_bulk_actions_reuse_single_action_rules_and_policies(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $vacancy = $this->vacancyIn($branch);
        $a = $this->applied($vacancy);
        $b = $this->applied($vacancy);
        $foreign = $this->applied($this->vacancyIn($other));
        $recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
        $ids = [$a->candidate_id, $b->candidate_id, $foreign->candidate_id];

        $this->actingAs($recruiter)->postJson('/api/candidates/bulk', ['action' => 'move', 'ids' => $ids, 'vacancy_id' => $vacancy->id, 'stage_id' => (string) $this->stageAt(2)->id])
            ->assertOk()
            ->assertJsonPath('data.0.ok', true)->assertJsonPath('data.1.ok', true)
            ->assertJsonPath('data.2.error', 'not_found'); // out of scope: indistinguishable from "no such candidate"
        $this->assertSame($this->stageAt(2)->id, $a->fresh()?->stage_id);

        // Same stage again → the single-action rule answers.
        $this->actingAs($recruiter)->postJson('/api/candidates/bulk', ['action' => 'move', 'ids' => [$a->candidate_id], 'vacancy_id' => $vacancy->id, 'stage_id' => $this->stageAt(2)->id])
            ->assertOk()->assertJsonPath('data.0.error', 'same_stage');

        $reason = RejectReason::query()->where('active', true)->firstOrFail();
        $this->actingAs($recruiter)->postJson('/api/candidates/bulk', ['action' => 'reject', 'ids' => [$a->candidate_id], 'vacancy_id' => $vacancy->id])->assertUnprocessable();
        $this->actingAs($recruiter)->postJson('/api/candidates/bulk', ['action' => 'reject', 'ids' => [$a->candidate_id], 'vacancy_id' => $vacancy->id, 'reject_reason_id' => $reason->id, 'reason' => 'bulk'])
            ->assertOk()->assertJsonPath('data.0.ok', true);
        $this->assertSame('rejected', $a->refresh()->status->value);

        $this->actingAs($recruiter)->postJson('/api/candidates/bulk', ['action' => 'tag', 'ids' => $ids, 'tag' => 'java'])
            ->assertOk()->assertJsonPath('data.0.ok', true)->assertJsonPath('data.2.error', 'not_found');
        $this->assertSame(['java'], $b->candidate()->firstOrFail()->tags);

        $this->actingAs($recruiter)->postJson('/api/candidates/bulk', ['action' => 'assign', 'ids' => [$b->candidate_id], 'owner_id' => $recruiter->id])
            ->assertOk()->assertJsonPath('data.0.ok', true);
        $this->assertSame($recruiter->id, $b->candidate()->firstOrFail()->owner_id);

        $this->actingAs($recruiter)->postJson('/api/candidates/bulk', ['action' => 'tag', 'ids' => range(1, 201), 'tag' => 'x'])->assertUnprocessable();
    }

    /** HRM-28: a bulk action leaves one audit row per changed object (meta.bulk), none for refused ids; journal is superadmin only. */
    public function test_bulk_actions_write_one_audit_row_per_object(): void
    {
        $branch = Branch::factory()->create();
        $vacancy = $this->vacancyIn($branch);
        $apps = [$this->applied($vacancy), $this->applied($vacancy), $this->applied($vacancy)];
        $foreign = $this->applied($this->vacancyIn(Branch::factory()->create()));
        $recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
        $ids = [...array_map(static fn ($a): int => $a->candidate_id, $apps), $foreign->candidate_id];
        AuditEntry::query()->delete(); // fixtures' own rows

        $this->actingAs($recruiter)->postJson('/api/candidates/bulk', ['action' => 'move', 'ids' => $ids, 'vacancy_id' => (string) $vacancy->id, 'stage_id' => (string) $this->stageAt(2)->id])
            ->assertOk()->assertJsonPath('data.3.error', 'not_found');
        $moved = AuditEntry::query()->where('action', 'stage_changed')->orderBy('id')->get();
        $this->assertSame(array_map(static fn ($a): int => $a->id, $apps), $moved->pluck('entity_id')->all());
        foreach ($moved as $i => $row) {
            $this->assertSame('application', $row->entity_type);
            $this->assertSame($recruiter->id, $row->user_id);
            $this->assertSame('candidates.move', $row->meta['bulk'] ?? null);
            $this->assertSame($apps[$i]->candidate_id, $row->meta['candidate_id'] ?? null);
        }
        $this->assertSame(0, AuditEntry::query()->where('entity_id', $foreign->id)->where('entity_type', 'application')->count());

        $this->actingAs($recruiter)->postJson('/api/candidates/bulk', ['action' => 'assign', 'ids' => $ids, 'owner_id' => $recruiter->id])->assertOk();
        $owned = AuditEntry::query()->where('entity_type', 'candidate')->where('meta->bulk', 'candidates.assign')->orderBy('entity_id')->get();
        $this->assertSame(array_slice($ids, 0, 3), $owned->pluck('entity_id')->all());
        $this->assertSame($recruiter->id, $owned[0]->changes['owner_id']['to'] ?? null);

        $this->actingAs($recruiter)->getJson('/api/audit')->assertForbidden();
    }

    /** Per-id errors must not tell whether a candidate out of scope exists, nor where they applied. */
    public function test_results_do_not_leak_candidates_or_applications_out_of_scope(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $vacancy = $this->vacancyIn($branch);
        $mine = $this->applied($vacancy);
        $foreign = $this->applied($this->vacancyIn($other));
        $recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
        $missing = $foreign->candidate_id + 1000;
        $url = '/api/candidates/bulk';

        foreach (['move' => ['stage_id' => $this->stageAt(2)->id], 'tag' => ['tag' => 'java']] as $action => $extra) {
            $results = $this->actingAs($recruiter)->postJson($url, [
                'action' => $action, 'ids' => [$foreign->candidate_id, $missing], 'vacancy_id' => $vacancy->id, ...$extra,
            ])->assertOk()->json('data');
            // Existing-but-invisible and simply absent must be indistinguishable.
            $this->assertSame($results[0]['error'], $results[1]['error'], $action.': existence leaked');
            $this->assertFalse($results[0]['ok']);
        }

        // A vacancy out of scope is refused once for the whole request, not probed candidate by candidate.
        $this->actingAs($recruiter)->postJson($url, [
            'action' => 'move', 'ids' => [$mine->candidate_id], 'vacancy_id' => $this->vacancyIn($other)->id, 'stage_id' => $this->stageAt(2)->id,
        ])->assertForbidden()->assertJsonPath('code', 'vacancy_out_of_scope');
    }
}
