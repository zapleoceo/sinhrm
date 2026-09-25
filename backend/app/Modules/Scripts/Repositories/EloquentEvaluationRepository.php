<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Repositories;

use App\Modules\Recruiting\DTO\DateRange;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Scripts\Contracts\EvaluationRepository;
use App\Modules\Scripts\Models\ScriptEvaluation;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentEvaluationRepository implements EvaluationRepository
{
    public function findByTouchpoint(int $touchpointId): ?ScriptEvaluation
    {
        return ScriptEvaluation::query()->with('version.script')->where('touchpoint_id', $touchpointId)->first();
    }

    public function createOnce(int $touchpointId, array $attributes): ScriptEvaluation
    {
        $existing = ScriptEvaluation::query()->where('touchpoint_id', $touchpointId)->first();
        if ($existing !== null) {
            return $existing;
        }
        try {
            return ScriptEvaluation::query()->create(['touchpoint_id' => $touchpointId] + $attributes);
        } catch (UniqueConstraintViolationException) {
            // Two evaluations of the same touch raced: the first one wins.
            return ScriptEvaluation::query()->where('touchpoint_id', $touchpointId)->firstOrFail();
        }
    }

    public function forTouchpoints(array $touchpointIds): array
    {
        if ($touchpointIds === []) {
            return [];
        }

        return ScriptEvaluation::query()->whereIn('touchpoint_id', $touchpointIds)->get()->keyBy('touchpoint_id')->all();
    }

    public function forReport(Scope $scope, DateRange $range, int $limit): array
    {
        $rows = DB::table('script_evaluations as e')
            ->join('touchpoints as t', 't.id', '=', 'e.touchpoint_id')
            ->leftJoin('users as u', 'u.id', '=', 't.author_id')
            ->leftJoin('applications as a', 'a.id', '=', 't.application_id')
            ->leftJoin('vacancies as v', 'v.id', '=', 'a.vacancy_id')
            ->whereBetween('t.occurred_at', [$range->from, $range->to])
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->where(function (Builder $w) use ($scope): void {
                $ids = $scope->branchIds ?? [];
                $w->where('t.author_id', $scope->userId)->orWhereIn('t.branch_id', $ids)->orWhereIn('v.branch_id', $ids);
            }))
            ->orderByDesc('t.occurred_at')
            ->orderByDesc('e.id')
            ->limit($limit)
            ->select(['t.author_id', 'u.name as author_name', 'e.score', 'e.result'])
            ->get();

        return array_values($rows->map(static function (object $r): array {
            $result = json_decode((string) $r->result, true);

            return [
                'author_id' => $r->author_id === null ? null : (int) $r->author_id,
                'author_name' => $r->author_name === null ? null : (string) $r->author_name,
                'score' => (int) $r->score,
                'result' => is_array($result) ? $result : [],
            ];
        })->all());
    }
}
