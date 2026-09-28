<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Demo\DemoDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/ops/demo-fill?confirm=demo[&reset=1] — synthetic "[ТЕСТ]" data for the report charts (X-Ops-Secret).
 * Without confirm=demo nothing happens (422). A repeated fill is a no-op; reset=1 deletes only rows listed in
 * demo_records. Triggered by the manual workflow .github/workflows/demo-fill.yml.
 */
final class OpsDemoFillController
{
    public function __invoke(Request $request, DemoDataService $demo): JsonResponse
    {
        if ($request->query('confirm') !== 'demo') {
            return new JsonResponse(['ok' => false, 'error' => 'confirm_required', 'hint' => 'add ?confirm=demo'], 422);
        }
        if ($request->boolean('reset')) {
            $deleted = $demo->reset();
            Log::info('ops.demo_reset', ['deleted' => $deleted]);

            return new JsonResponse(['ok' => true, 'action' => 'reset', 'deleted' => (object) $deleted]);
        }
        $result = $demo->fill();
        Log::info('ops.demo_fill', $result);

        return new JsonResponse(['ok' => true, 'action' => 'fill', 'already' => $result['already'], 'counts' => (object) $result['counts']]);
    }
}
