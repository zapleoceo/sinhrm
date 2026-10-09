<?php

declare(strict_types=1);

namespace Tests\Unit\Overview;

use App\Models\User;
use App\Modules\Overview\Contracts\DashboardRepository;
use App\Modules\Overview\Services\DashboardService;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\RecruitingAccess;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Scripts\Contracts\TaskReader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The home page reads Recruiting and Scripts only through their contracts (RecruitingAccess, ApplicationRepository,
 * TaskReader): with empty answers the page is built without touching the database.
 */
final class DashboardContractsTest extends TestCase
{
    public function test_the_page_is_built_from_the_contracts(): void
    {
        $now = Carbon::parse('2026-10-08 10:00:00');
        $user = new User;
        $user->id = 4;
        $scope = new Scope(4, []);
        /** @var RecruitingAccess&MockInterface $access */
        $access = $this->mock(RecruitingAccess::class);
        $access->expects('for')->with($user)->andReturn($scope);
        /** @var TaskReader&MockInterface $tasks */
        $tasks = $this->mock(TaskReader::class);
        $tasks->expects('list')->andReturn(new Collection);
        /** @var ApplicationRepository&MockInterface $applications */
        $applications = $this->mock(ApplicationRepository::class);
        $applications->expects('stale')->with($scope, Mockery::on(static fn (Carbon $before): bool => $before->eq($now->copy()->subDays(ApplicationRepository::STALE_DAYS))), DashboardService::STALE_LIST)
            ->andReturn(new Collection);
        /** @var DashboardRepository&MockInterface $dashboard */
        $dashboard = $this->mock(DashboardRepository::class);
        $dashboard->shouldIgnoreMissing([]);

        $data = $this->app->make(DashboardService::class, ['notices' => [], 'sections' => []])->build($user, $now);

        $this->assertSame(ApplicationRepository::STALE_DAYS, $data['stale_days']);
        $this->assertSame(['total' => 0, 'overdue' => 0, 'items' => []], $data['my_tasks']);
        $this->assertSame([], $data['stale']);
    }
}
