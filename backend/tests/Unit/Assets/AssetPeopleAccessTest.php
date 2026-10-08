<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use App\Models\User;
use App\Modules\Assets\Http\Controllers\AssetController;
use App\Modules\People\Contracts\EmployeeLookup;
use App\Modules\People\Contracts\PeopleAccess;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** Assets reaches People only through its contracts (PeopleAccess, EmployeeLookup) — no DB here. */
final class AssetPeopleAccessTest extends TestCase
{
    public function test_assets_of_an_employee_outside_the_scope_are_404_before_any_lookup(): void
    {
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess);
        /** @var EmployeeLookup&MockInterface $employees */
        $employees = $this->mock(EmployeeLookup::class);
        $employees->expects('find')->never();
        $user = new User;
        $user->id = 1;
        $request = Request::create('/api/assets/employee/77');
        $request->setUserResolver(static fn (): User => $user);

        $this->expectException(NotFoundHttpException::class);
        $this->app->make(AssetController::class)->ofEmployee($request, 77);
    }
}
