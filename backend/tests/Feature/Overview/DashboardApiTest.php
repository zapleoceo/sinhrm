<?php

declare(strict_types=1);

namespace Tests\Feature\Overview;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class DashboardApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_is_scoped_to_the_users_branches(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $recruiter = $this->userWith(UserRole::Recruiter, [$branch]);

        Carbon::setTestNow('2026-09-01 10:00:00');
        $stale = $this->applied($this->vacancyIn($branch), ['full_name' => 'Stale One', 'phone' => '+380670000100']);
        $this->applied($this->vacancyIn($other), ['full_name' => 'Foreign Stale']);
        Carbon::setTestNow('2026-09-10 08:00:00');
        $fresh = $this->applied($this->vacancyIn($branch), ['full_name' => 'Fresh One', 'phone' => '+380670000101']);
        $this->ingest(Channel::Telegram, '+380670000101', ['at' => Carbon::parse('2026-09-09 10:00')]);
        $this->ingest(Channel::Call, '+380670000101', ['at' => Carbon::parse('2026-09-10 07:00')]);
        $this->ingest(Channel::Viber, '+380999999999', ['branch_id' => $branch->id]);      // unmatched, my branch
        $this->ingest(Channel::Viber, '+380999999998', ['branch_id' => $other->id]);       // unmatched, other branch
        Task::query()->create(['assignee_id' => $recruiter->id, 'candidate_id' => $stale->candidate_id, 'application_id' => $stale->id,
            'type' => 'followup', 'title' => 'Remind', 'due_at' => Carbon::parse('2026-09-08 10:00')]);
        Task::query()->create(['assignee_id' => $recruiter->id, 'candidate_id' => $fresh->candidate_id, 'application_id' => $fresh->id,
            'type' => 'manual', 'title' => 'Later', 'due_at' => Carbon::parse('2026-09-20 10:00')]);
        Carbon::setTestNow('2026-09-10 12:00:00');

        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->actingAs($recruiter)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.counts', ['active' => 2, 'stale' => 1, 'unmatched_inbox' => 1, 'new_today' => 1])
            ->assertJsonPath('data.stale_days', 3)
            ->assertJsonPath('data.stale.0.candidate.name', 'Stale One')
            ->assertJsonPath('data.stale.0.days', 9)
            ->assertJsonCount(1, 'data.stale')
            ->assertJsonPath('data.my_tasks.total', 1)
            ->assertJsonPath('data.my_tasks.overdue', 1)
            ->assertJsonPath('data.my_tasks.items.0.candidate.name', 'Stale One')
            ->assertJsonPath('data.funnel.0.count', 2)
            ->assertJsonPath('data.touches.days', 7)
            ->assertJsonPath('data.touches.by_channel', [['channel' => 'call', 'count' => 1], ['channel' => 'telegram', 'count' => 1], ['channel' => 'viber', 'count' => 1]]);

        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.counts.active', 3)
            ->assertJsonPath('data.counts.stale', 2)
            ->assertJsonPath('data.counts.unmatched_inbox', 2)
            ->assertJsonPath('data.my_tasks.total', 0);

        $this->actingAs($this->userWith(UserRole::Viewer))->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.counts', ['active' => 0, 'stale' => 0, 'unmatched_inbox' => 0, 'new_today' => 0])
            ->assertJsonPath('data.funnel', []);
    }
}
