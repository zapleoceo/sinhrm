<?php

declare(strict_types=1);

namespace Tests\Feature\HiringRequests;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\RejectReason;
use App\Modules\Recruiting\Models\StageChange;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** One real HTTP/DB journey; sequential retry checks are not a concurrency or exactly-once guarantee. */
final class HiringApiAcceptanceTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approved_vacancy_http_decisions_reach_exact_scoped_reports(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $admin = $this->userWith(UserRole::Admin);
        $recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
        $foreign = $this->userWith(UserRole::Recruiter, [$other]);
        $viewer = $this->userWith(UserRole::Viewer, [$branch]);
        $this->actingAs($admin)->putJson('/api/hiring-requests/settings', [
            'auto_vacancy' => true,
            'route' => [['name' => 'HR', 'kind' => 'role', 'role' => 'admin', 'sla_days' => 1]],
        ])->assertOk();
        $requestId = (int) $this->postJson('/api/hiring-requests', [
            'title' => 'Synthetic API acceptance', 'branch_id' => $branch->id,
            'headcount' => 1, 'reason' => 'new_position', 'submit' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $vacancyId = (int) $this->postJson("/api/hiring-requests/$requestId/decision", [
            'decision' => 'approve', 'recruiter_id' => $recruiter->id,
        ])->assertOk()->assertJsonPath('data.status', 'in_progress')->json('data.vacancy.id');
        $this->postJson("/api/hiring-requests/$requestId/decision", ['decision' => 'approve'])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_status');
        $this->postJson("/api/hiring-requests/$requestId/vacancy")->assertOk()->assertJsonPath('data.vacancy.id', $vacancyId);
        $this->assertSame(1, Vacancy::query()->count());
        $this->actingAs($recruiter)->getJson("/api/vacancies/$vacancyId")->assertOk()
            ->assertJsonPath('data.branch.id', $branch->id)->assertJsonPath('data.recruiter.id', $recruiter->id);

        [$hireCandidate, $hire] = $this->createAndApply($recruiter, $vacancyId, 'hire@example.test');
        [, $reject] = $this->createAndApply($recruiter, $vacancyId, 'reject@example.test');
        $foreignVacancy = $this->vacancyIn($other, $foreign);
        [, $foreignReject] = $this->createAndApply($foreign, $foreignVacancy->id, 'foreign@example.test');
        $select = $this->stageAt(2);
        $hired = $this->hireStage();
        $rejected = $this->rejectStage();
        $reason = RejectReason::query()->where('active', true)->firstOrFail();

        $this->actingAs($viewer)->postJson("/api/applications/$hire/move", ['stage_id' => $select->id])->assertForbidden();
        $this->actingAs($foreign)->postJson("/api/applications/$hire/move", ['stage_id' => $select->id])->assertForbidden();
        $this->actingAs($recruiter)->postJson("/api/applications/$foreignReject/move", ['stage_id' => $select->id])->assertForbidden();
        $this->assertSame(1, StageChange::query()->where('application_id', $hire)->count());
        $this->actingAs($recruiter)->postJson("/api/applications/$hire/move", ['stage_id' => $select->id])
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/applications/$hire/move", ['stage_id' => $hired->id])
            ->assertOk()->assertJsonPath('data.status', 'hired');
        $this->postJson("/api/applications/$hire/move", ['stage_id' => $hired->id])
            ->assertUnprocessable()->assertJsonPath('code', 'same_stage');
        $this->assertSame(3, StageChange::query()->where('application_id', $hire)->count());

        $this->postJson("/api/applications/$reject/move", ['stage_id' => $rejected->id])
            ->assertUnprocessable()->assertJsonPath('code', 'reject_reason_required');
        $this->assertSame('active', Application::query()->findOrFail($reject)->status->value);
        $body = ['stage_id' => $rejected->id, 'reject_reason_id' => $reason->id];
        $this->postJson("/api/applications/$reject/move", $body)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/applications/$reject/move", $body)->assertUnprocessable()->assertJsonPath('code', 'same_stage');
        $this->actingAs($foreign)->postJson("/api/applications/$foreignReject/move", $body)->assertOk();

        $this->actingAs($recruiter)->postJson('/api/candidates', ['full_name' => 'Duplicate fixture', 'email' => 'hire@example.test'])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_candidate')->assertJsonPath('existing_id', $hireCandidate);
        $this->postJson("/api/vacancies/$vacancyId/applications", ['candidate_id' => $hireCandidate])
            ->assertStatus(409)->assertJsonPath('code', 'already_applied');
        $this->assertSame(3, Candidate::query()->count());
        $this->assertSame(3, Application::query()->count());
        $this->assertSame(2, StageChange::query()->where('application_id', $reject)->count());

        $range = '?from=2026-10-05&to=2026-10-05';
        $rows = $this->getJson('/api/reports/funnel'.$range)->assertOk()->assertJsonPath('data.totals.total', 2)->json('data.rows');
        $this->assertIsArray($rows);
        $this->assertSame([$vacancyId], array_values(array_unique(array_column($rows, 'vacancy_id'))));
        $this->assertEquals([$hired->id => 1, $rejected->id => 1], array_column($rows, 'count', 'stage_id'));
        $this->getJson('/api/reports/reject-reasons'.$range)->assertOk()->assertJsonPath('data.totals.total', 1)
            ->assertJsonPath('data.rows', [['reject_reason_id' => $reason->id, 'name' => $reason->name, 'count' => 1]]);
        $this->getJson('/api/reports/sources'.$range)->assertOk()
            ->assertJsonPath('data.totals', ['candidates' => 2, 'hired' => 1]);
        $this->getJson('/api/reports/catalog/recruiting_funnel'.$range)->assertOk()->assertJsonPath('data.rows', [
            ['stage' => $hired->name, 'applications' => 1], ['stage' => $rejected->name, 'applications' => 1],
        ]);
        $this->getJson('/api/reports/catalog/reject_reasons'.$range)->assertOk()
            ->assertJsonPath('data.rows', [['reason' => $reason->name, 'rejections' => 1]]);
        $this->getJson('/api/reports/reject-reasons?from=2026-10-06&to=2026-10-06')->assertOk()->assertJsonPath('data.rows', []);
        $this->getJson('/api/reports/funnel?from=2026-10-06&to=2026-10-05')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/reports/reject-reasons'.$range)->assertOk()->assertJsonPath('data.totals.total', 2);
        $this->getJson("/api/hiring-requests/$requestId")->assertOk()
            ->assertJsonPath('data.progress.hired', 1)->assertJsonPath('data.progress.percent', 100);
    }

    /** @return array{int, int} */
    private function createAndApply(User $actor, int $vacancyId, string $email): array
    {
        $candidateId = (int) $this->actingAs($actor)->postJson('/api/candidates', [
            'full_name' => 'Synthetic acceptance candidate', 'email' => $email, 'source' => 'referral',
        ])->assertCreated()->json('data.id');
        $applicationId = (int) $this->postJson("/api/vacancies/$vacancyId/applications", ['candidate_id' => $candidateId])
            ->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');

        return [$candidateId, $applicationId];
    }
}
