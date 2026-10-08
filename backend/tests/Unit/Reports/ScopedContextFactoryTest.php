<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Models\User;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\Recruiting\Contracts\RecruitingAccess;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Reports\Services\ScopedContextFactory;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** The report scope is exactly what People's PeopleAccess and Recruiting's RecruitingAccess contracts give (no DB). */
final class ScopedContextFactoryTest extends TestCase
{
    public function test_the_context_combines_both_contracts(): void
    {
        $user = new User;
        $user->id = 6;
        $scope = new Scope(6, [2, 3]);
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess(admin: false, subtree: [8]));
        /** @var RecruitingAccess&MockInterface $recruiting */
        $recruiting = $this->mock(RecruitingAccess::class);
        $recruiting->expects('for')->with($user)->twice()->andReturn($scope);
        $now = Carbon::parse('2026-10-08 09:00:00');

        $ctx = $this->app->make(ScopedContextFactory::class)->for($user, $now);

        $this->assertSame($scope, $ctx->recruiting);
        $this->assertSame([8], $ctx->people->subtreeIds);
        $this->assertFalse($ctx->people->admin);
        // The same moment in the user's zone: "today" of the reports is the Kyiv date.
        $this->assertTrue($now->equalTo($ctx->now));
        $this->assertSame('Europe/Kyiv', $ctx->now->getTimezone()->getName());
        $this->assertSame('UTC', $now->getTimezone()->getName(), 'the input is not changed');
        $night = $this->app->make(ScopedContextFactory::class)->for($user, Carbon::parse('2026-12-31 22:30:00'));
        $this->assertSame('2027-01-01', $night->now->toDateString());
    }
}
