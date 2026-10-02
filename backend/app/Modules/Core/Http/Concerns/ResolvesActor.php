<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in user of a request, for controllers behind auth:sanctum (+ EnsureUserIsActive).
 * One place instead of a private actor() copy in every controller; a guest never reaches these routes,
 * so a missing user is a programming error (assert), not a 401.
 */
trait ResolvesActor
{
    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
