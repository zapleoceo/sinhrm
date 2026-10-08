<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Models\User;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\Time\Services\TimeDashboardSection;
use Illuminate\Support\Carbon;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** The home block "time" asks People through PeopleAccess: no employee card and no team — empty block (no DB). */
final class TimePeopleAccessTest extends TestCase
{
    public function test_no_employee_and_no_team_gives_an_empty_block(): void
    {
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess);

        $data = $this->app->make(TimeDashboardSection::class)->data($this->user(), Carbon::parse('2026-10-08'));

        $this->assertSame(['my_week' => null, 'my_approvals' => ['count' => 0, 'items' => []]], $data);
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 3;

        return $user;
    }
}
