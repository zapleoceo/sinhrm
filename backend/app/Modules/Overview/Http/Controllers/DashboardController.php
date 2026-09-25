<?php

declare(strict_types=1);

namespace App\Modules\Overview\Http\Controllers;

use App\Models\User;
use App\Modules\Overview\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/dashboard — the home page data ({data: {counts, my_tasks, stale, funnel, touches}}). */
final class DashboardController
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return new JsonResponse(['data' => $this->dashboard->build($actor)]);
    }
}
