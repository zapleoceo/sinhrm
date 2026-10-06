<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\HealthService;
use App\Modules\Core\Support\BuildVersion;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __invoke(HealthService $health): JsonResponse
    {
        $report = $health->report();

        return response()->json(
            ['version' => BuildVersion::fromSha(config('build.sha'))] + $report,
            $report['ok'] ? 200 : 503,
        );
    }
}
