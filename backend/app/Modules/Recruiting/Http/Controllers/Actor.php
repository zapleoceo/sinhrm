<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

/** The signed-in user of a request (routes are behind auth:sanctum). */
trait Actor
{
    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
