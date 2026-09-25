<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\PipelineStage;
use App\Modules\Recruiting\Models\RejectReason;
use App\Modules\Recruiting\Models\StageChange;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class MoveApplicationTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Branch $branch;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->application = $this->applied($this->vacancyIn($this->branch));
    }

    public function test_move_records_stage_change_and_system_touchpoint(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $to = $this->stageAt(3);

        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/move", ['stage_id' => (string) $to->id, 'reason' => 'invited'])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $to->id)
            ->assertJsonPath('data.status', 'active');

        $change = StageChange::query()->where('application_id', $this->application->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame($this->stageAt(1)->id, $change->from_stage_id);
        $this->assertSame($to->id, $change->to_stage_id);
        $this->assertSame($recruiter->id, $change->by_user_id);
        $this->assertSame('invited', $change->reason);
        $system = Touchpoint::query()->where('stage_change_id', $change->id)->firstOrFail();
        $this->assertSame('system', $system->channel->value);
        // A system event is not a contact: staleness is not reset.
        $this->assertNull($this->application->fresh()?->last_touch_at);
    }

    public function test_reject_requires_reason_and_sets_status(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $reject = $this->rejectStage();
        $reason = RejectReason::query()->where('active', true)->firstOrFail();

        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/move", ['stage_id' => $reject->id])
            ->assertUnprocessable()->assertJsonPath('code', 'reject_reason_required');
        $inactive = RejectReason::query()->create(['name' => 'Old reason', 'active' => false]);
        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/move", ['stage_id' => $reject->id, 'reject_reason_id' => $inactive->id])
            ->assertUnprocessable();

        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/move", [
            'stage_id' => $reject->id, 'reject_reason_id' => $reason->id, 'reason' => 'no answer',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reject_reason_id', $reason->id)
            ->assertJsonPath('data.rejected_note', 'no answer');
        $this->assertNotNull($this->application->fresh()?->closed_at);

        // Moving back reopens and clears the rejection.
        $this->actingAs($recruiter)->postJson("/api/applications/{$this->application->id}/move", ['stage_id' => $this->stageAt(2)->id])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.reject_reason_id', null)->assertJsonPath('data.closed_at', null);
    }

    public function test_hire_stage_marks_hired(): void
    {
        $admin = $this->userWith(UserRole::Admin);
        $this->actingAs($admin)->postJson("/api/applications/{$this->application->id}/move", ['stage_id' => $this->hireStage()->id])
            ->assertOk()->assertJsonPath('data.status', 'hired');
    }

    public function test_invalid_moves(): void
    {
        $admin = $this->userWith(UserRole::Admin);
        $foreignStage = PipelineStage::query()->create([
            'pipeline_id' => $this->app->make(PipelineRepository::class)
                ->create('Other', [['name' => 'A', 'kind' => 'attract', 'is_terminal' => false], ['name' => 'Z', 'kind' => 'closed', 'is_terminal' => true]])->id,
            'name' => 'Extra', 'kind' => 'select', 'position' => 3, 'is_terminal' => false,
        ]);

        $this->actingAs($admin)->postJson("/api/applications/{$this->application->id}/move", ['stage_id' => $this->stageAt(1)->id])
            ->assertUnprocessable()->assertJsonPath('code', 'same_stage');
        $this->actingAs($admin)->postJson("/api/applications/{$this->application->id}/move", ['stage_id' => $foreignStage->id])
            ->assertUnprocessable()->assertJsonPath('code', 'stage_not_in_pipeline');
        $this->actingAs($admin)->postJson("/api/applications/{$this->application->id}/move", ['stage_id' => 999999])
            ->assertUnprocessable()->assertJsonPath('code', 'stage_not_in_pipeline');
        $this->actingAs($admin)->postJson("/api/applications/{$this->application->id}/move", [])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/applications/999999/move', ['stage_id' => 1])->assertNotFound();
    }

    public function test_authorization_by_role_and_branch(): void
    {
        $body = ['stage_id' => $this->stageAt(2)->id];
        $this->postJson("/api/applications/{$this->application->id}/move", $body)->assertUnauthorized();
        $this->actingAs($this->userWith(UserRole::Viewer, [$this->branch]))
            ->postJson("/api/applications/{$this->application->id}/move", $body)->assertForbidden();
        $this->actingAs($this->userWith(UserRole::Recruiter, [Branch::factory()->create()]))
            ->postJson("/api/applications/{$this->application->id}/move", $body)->assertForbidden();
        $this->actingAs($this->userWith(UserRole::Superadmin))
            ->postJson("/api/applications/{$this->application->id}/move", $body)->assertOk();
    }
}
