<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Contracts;

use App\Modules\Recruiting\DTO\DateRange;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Scripts\Models\ScriptEvaluation;

interface EvaluationRepository
{
    public function findByTouchpoint(int $touchpointId): ?ScriptEvaluation;

    /**
     * Idempotent: an existing evaluation of the touchpoint is returned unchanged.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOnce(int $touchpointId, array $attributes): ScriptEvaluation;

    /**
     * @param  list<int>  $touchpointIds
     * @return array<int, ScriptEvaluation> touchpoint id → evaluation
     */
    public function forTouchpoints(array $touchpointIds): array;

    /**
     * Evaluations of touches that happened in the range, within the scope (author = user, or branch of the line or of
     * the vacancy), newest first, at most $limit. The report aggregates them (jsonb stays portable: no JSON SQL).
     *
     * @return list<array{author_id: int|null, author_name: string|null, score: int, result: array<string, mixed>}>
     */
    public function forReport(Scope $scope, DateRange $range, int $limit): array;
}
