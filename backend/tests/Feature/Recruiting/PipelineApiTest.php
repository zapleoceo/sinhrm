<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Recruiting\Models\RejectReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class PipelineApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    public function test_default_pipeline_is_seeded_by_migration(): void
    {
        $this->actingAs($this->userWith(UserRole::Viewer))->getJson('/api/pipelines')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonCount(8, 'data.0.stages')
            ->assertJsonPath('data.0.stages.0.kind', 'attract')
            ->assertJsonPath('data.0.stages.6.is_hire', true)
            ->assertJsonPath('data.0.stages.7.is_reject', true);
    }

    public function test_admin_creates_pipeline_with_rules(): void
    {
        $admin = $this->userWith(UserRole::Admin);
        $stages = [
            ['name' => 'New', 'kind' => 'attract'],
            ['name' => 'Talk', 'kind' => 'select'],
            ['name' => 'Rejected', 'kind' => 'closed', 'is_terminal' => true],
        ];
        $this->actingAs($admin)->postJson('/api/pipelines', ['name' => 'Short', 'stages' => $stages])
            ->assertCreated()->assertJsonPath('data.is_default', false)->assertJsonCount(3, 'data.stages');
        $this->actingAs($admin)->postJson('/api/pipelines', ['name' => 'Bad', 'stages' => array_slice($stages, 0, 2)])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/pipelines', ['name' => 'Bad', 'stages' => [$stages[2], $stages[0]]])->assertUnprocessable();
        $this->actingAs($this->userWith(UserRole::Recruiter))->postJson('/api/pipelines', ['name' => 'X', 'stages' => $stages])->assertForbidden();
    }

    public function test_reject_reasons_dictionary(): void
    {
        $admin = $this->userWith(UserRole::Admin);
        $recruiter = $this->userWith(UserRole::Recruiter);
        $seeded = RejectReason::query()->count();
        $this->assertGreaterThan(0, $seeded);

        $id = $this->actingAs($admin)->postJson('/api/reject-reasons', ['name' => 'Moved away'])->assertCreated()->json('data.id');
        $this->actingAs($admin)->patchJson("/api/reject-reasons/$id", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->actingAs($recruiter)->getJson('/api/reject-reasons')->assertOk()->assertJsonCount($seeded, 'data');
        $this->actingAs($recruiter)->getJson('/api/reject-reasons?all=1')->assertOk()->assertJsonCount($seeded + 1, 'data');
        $this->actingAs($recruiter)->postJson('/api/reject-reasons', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($recruiter)->patchJson("/api/reject-reasons/$id", ['active' => true])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/reject-reasons', ['name' => ''])->assertUnprocessable();
        $this->actingAs($admin)->patchJson('/api/reject-reasons/999999', ['active' => true])->assertNotFound();
    }
}
