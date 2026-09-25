<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Jobs;

use App\Modules\Scripts\Services\EvaluationService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Evaluates one touch. Dispatched with dispatchAfterResponse(): runs synchronously in the same PHP process right
 * after the HTTP answer is sent (no queue worker on Vercel). A failure is logged and never breaks the request.
 */
final class EvaluateTouchpoint
{
    use Dispatchable;

    public function __construct(public readonly int $touchpointId) {}

    public function handle(EvaluationService $service): void
    {
        try {
            $evaluation = $service->evaluateTouchpoint($this->touchpointId);
            if ($evaluation !== null) {
                Log::info('scripts.touch_evaluated', [
                    'touchpoint_id' => $this->touchpointId,
                    'engine' => $evaluation->engine->value,
                    'score' => $evaluation->score,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
