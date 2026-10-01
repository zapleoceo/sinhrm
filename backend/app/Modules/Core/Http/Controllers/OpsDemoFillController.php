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
 * /api/ops/demo-fill?confirm=demo — synthetic data (names end with " [ТЕСТ]", one branch "Тестовий філіал") for the report charts (X-Ops-Secret), one step per request:
 * - GET|POST &steps=list → {steps: [...order], done: [...]}
 * - POST &step=<name>    → runs that step in its own transaction (done step → already=true; earlier step missing → 409)
 * - GET|POST &reset=1&dry=1 → what reset would delete: legacy test rows (DemoLegacy), registered rows, side effects; changes nothing
 * - POST &reset=1        → registers the legacy test rows, then deletes only rows listed in demo_records
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
        if ($request->boolean('reset') && $request->boolean('dry')) {
            return new JsonResponse(['ok' => true, 'action' => 'reset-dry-run', ...array_map(static fn (array $part): object => (object) $part, $demo->resetPreview())]);
        }
        if (! $request->isMethod('POST')) {
            return $this->fail('post_required', 405);
        }
        if ($request->boolean('reset')) {
            $result = $demo->reset();
            Log::info('ops.demo_reset', $result);

            return new JsonResponse(['ok' => true, 'action' => 'reset', 'legacy' => (object) $result['legacy'], 'deleted' => (object) $result['deleted']]);
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
