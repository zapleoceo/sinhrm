<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Models\User;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\DTO\MoveData;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\RejectReason;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class ApplicationServiceTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    public function test_status_follows_the_stage_and_dates_are_kept(): void
    {
        $service = $this->app->make(ApplicationService::class);
        $actor = User::factory()->create();
        $application = $this->applied($this->vacancyIn(Branch::factory()->create()), [], Carbon::parse('2026-09-01 09:00:00'));
        $this->assertSame('2026-09-01 09:00:00', $application->stage_entered_at?->format('Y-m-d H:i:s'));

        $hired = $service->move($actor, $application, new MoveData($this->hireStage()->id), Carbon::parse('2026-09-02 09:00:00'));
        $this->assertSame(ApplicationStatus::Hired, $hired->status);
        $this->assertSame('2026-09-02 09:00:00', $hired->closed_at?->format('Y-m-d H:i:s'));

        $reason = RejectReason::query()->firstOrFail();
        $rejected = $service->move($actor, $hired, new MoveData($this->rejectStage()->id, 'why', $reason->id));
        $this->assertSame(ApplicationStatus::Rejected, $rejected->status);
        $this->assertSame($reason->id, $rejected->reject_reason_id);
        $this->assertSame(3, $rejected->stageChanges()->count());
    }

    public function test_apply_twice_is_refused(): void
    {
        $vacancy = $this->vacancyIn(Branch::factory()->create());
        $application = $this->applied($vacancy);

        $this->expectException(RecruitingException::class);
        $this->app->make(ApplicationService::class)->apply(null, $application->candidate, $vacancy);
    }
}
