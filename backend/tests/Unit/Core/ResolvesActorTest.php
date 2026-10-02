<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Models\User;
use App\Modules\Core\Http\Concerns\ResolvesActor;
use Illuminate\Http\Request;
use Tests\TestCase;

/** The shared actor() of controllers: the request's signed-in user. */
final class ResolvesActorTest extends TestCase
{
    public function test_it_returns_the_signed_in_user_of_the_request(): void
    {
        $user = new User;
        $request = Request::create('/api/anything');
        $request->setUserResolver(static fn (): User => $user);

        $controller = new class
        {
            use ResolvesActor;

            public function who(Request $request): User
            {
                return $this->actor($request);
            }
        };

        $this->assertSame($user, $controller->who($request));
    }
}
