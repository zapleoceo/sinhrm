<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Enums\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class StaleApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    public function test_stale_applications_by_days_passed_as_string(): void
    {
        $north = Branch::factory()->create();
        $south = Branch::factory()->create();
        $vacancy = $this->vacancyIn($north);
        // Created 10 days ago, never touched → stale for days=3 and days=7.
        $old = $this->applied($vacancy, ['phone' => '+380671000001'], Carbon::now()->subDays(10));
        $old->forceFill(['created_at' => Carbon::now()->subDays(10)])->save();
        // Created 10 days ago but touched 2 days ago → stale for days=1 only.
        $touched = $this->applied($vacancy, ['phone' => '+380671000002'], Carbon::now()->subDays(10));
        $touched->forceFill(['created_at' => Carbon::now()->subDays(10)])->save();
        $this->ingest(Channel::Whatsapp, '+380671000002', ['at' => Carbon::now()->subDays(2)]);
        // Fresh.
        $this->applied($vacancy, ['phone' => '+380671000003']);
        // Stale but in another branch.
        $foreign = $this->applied($this->vacancyIn($south), [], Carbon::now()->subDays(10));
        $foreign->forceFill(['created_at' => Carbon::now()->subDays(10)])->save();

        $recruiter = $this->userWith(UserRole::Recruiter, [$north]);
        $this->actingAs($recruiter)->getJson('/api/recruiting/stale?days=3')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id)
            ->assertJsonPath('data.0.is_stale', true)
            ->assertJsonPath('data.0.candidate.phone', '+380671000001')
            ->assertJsonPath('meta.days', 3);
        $this->actingAs($recruiter)->getJson('/api/recruiting/stale?days=1')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $old->id);
        // Default is 3 days.
        $this->actingAs($recruiter)->getJson('/api/recruiting/stale')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/recruiting/stale?days=3')->assertOk()->assertJsonCount(2, 'data');

        foreach (['days=0', 'days=abc', 'days=366', 'days=1.5'] as $q) {
            $this->actingAs($recruiter)->getJson("/api/recruiting/stale?$q")->assertUnprocessable();
        }
    }

    public function test_rejected_applications_are_never_stale(): void
    {
        $this->getJson('/api/recruiting/stale')->assertUnauthorized();
        $branch = Branch::factory()->create();
        $app = $this->applied($this->vacancyIn($branch), [], Carbon::now()->subDays(10));
        $app->forceFill(['created_at' => Carbon::now()->subDays(10), 'status' => 'rejected'])->save();

        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/recruiting/stale?days=3')->assertOk()->assertJsonCount(0, 'data');
    }
}
