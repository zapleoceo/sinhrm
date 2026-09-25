<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

/**
 * Short script-evaluation summaries shown on timeline items. Recruiting does not know how touches are evaluated:
 * the Scripts module binds its implementation; without it (NullTouchpointEvaluations) there are none.
 */
interface TouchpointEvaluations
{
    /**
     * @param  list<int>  $touchpointIds
     * @return array<int, array<string, mixed>> touchpoint id → summary (missing id = not evaluated)
     */
    public function summaries(array $touchpointIds): array;
}
