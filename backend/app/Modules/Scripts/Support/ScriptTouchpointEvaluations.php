<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Support;

use App\Modules\Recruiting\Contracts\TouchpointEvaluations;
use App\Modules\Scripts\Services\EvaluationService;

/** Plugs script evaluations into the Recruiting timeline (touchpoint.evaluation). */
final readonly class ScriptTouchpointEvaluations implements TouchpointEvaluations
{
    public function __construct(private EvaluationService $service) {}

    public function summaries(array $touchpointIds): array
    {
        return $this->service->summaries($touchpointIds);
    }
}
