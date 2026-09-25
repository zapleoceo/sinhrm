<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\HealthService;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __invoke(HealthService $health): JsonResponse
    {
        $report = $health->report();

        return response()->json(
            ['version' => config('app.version')] + $report,
            $report['ok'] ? 200 : 503,
        );
    }
}
