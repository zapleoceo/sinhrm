<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Demo\DemoDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * /api/ops/demo-fill?confirm=demo — synthetic "[ТЕСТ]" data for the report charts (X-Ops-Secret), one step per request:
 * - GET|POST &steps=list → {steps: [...order], done: [...]}
 * - POST &step=<name>    → runs that step in its own transaction (done step → already=true; earlier step missing → 409)
 * - POST &reset=1        → deletes only rows listed in demo_records
 * Without confirm=demo nothing happens (422). Driven by .github/workflows/demo-fill.yml.
 */
final class OpsDemoFillController
{
    public function __invoke(Request $request, DemoDataService $demo): JsonResponse
    {
        if ($request->query('confirm') !== 'demo') {
            return $this->fail('confirm_required', 422);
        }
        if ($request->query('steps') === 'list') {
            return new JsonResponse(['ok' => true, 'steps' => $demo->steps(), 'done' => $demo->done()]);
        }
        if (! $request->isMethod('POST')) {
            return $this->fail('post_required', 405);
        }
        if ($request->boolean('reset')) {
            $deleted = $demo->reset();
            Log::info('ops.demo_reset', ['deleted' => $deleted]);

            return new JsonResponse(['ok' => true, 'action' => 'reset', 'deleted' => (object) $deleted]);
        }
        $step = $request->query('step');
        if (! is_string($step) || $step === '') {
            return $this->fail('step_required', 422);
        }
        try {
            $result = $demo->run($step);
        } catch (InvalidArgumentException) {
            return $this->fail('unknown_step', 422);
        } catch (RuntimeException $e) {
            if (! str_starts_with($e->getMessage(), 'previous_step_missing')) {
                throw $e;
            }

            return $this->fail($e->getMessage(), 409);
        }
        Log::info('ops.demo_fill', $result);

        return new JsonResponse(['ok' => true, 'action' => 'fill', 'step' => $result['step'], 'already' => $result['already'], 'counts' => (object) $result['counts']]);
    }

    private function fail(string $error, int $status): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $error], $status);
    }
}
