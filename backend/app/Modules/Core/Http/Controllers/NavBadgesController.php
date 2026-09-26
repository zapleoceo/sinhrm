<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Services\NavBadgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/nav/badges — sidebar counters of the current user only, e.g. {"data": {"tasks": 1, "inbox": 4}}. */
final class NavBadgesController
{
    public function __invoke(Request $request, NavBadgeService $badges): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return new JsonResponse(['data' => (object) $badges->for($user)]);
    }
}
