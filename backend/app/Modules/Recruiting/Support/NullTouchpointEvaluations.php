<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Support;

use App\Modules\Recruiting\Contracts\TouchpointEvaluations;

/** Default when no module evaluates touches. */
final class NullTouchpointEvaluations implements TouchpointEvaluations
{
    public function summaries(array $touchpointIds): array
    {
        return [];
    }
}
