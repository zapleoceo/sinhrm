<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\StageChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentApplicationRepository implements ApplicationRepository
{
    public function find(int $id): ?Application
    {
        return Application::query()->with(['vacancy', 'stage', 'candidate'])->find($id);
    }

    public function findFor(int $candidateId, int $vacancyId): ?Application
    {
        return Application::query()->where('candidate_id', $candidateId)->where('vacancy_id', $vacancyId)->first();
    }

    public function latestActiveFor(int $candidateId): ?Application
    {
        return Application::query()
            ->where('candidate_id', $candidateId)
            ->where('status', ApplicationStatus::Active->value)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    public function forCandidate(int $candidateId): Collection
    {
        return Application::query()
            ->with(['vacancy.branch', 'vacancy.pipeline.stages', 'stage', 'rejectReason', 'stageChanges.toStage', 'stageChanges.byUser', 'interviewers'])
            ->where('candidate_id', $candidateId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    public function create(array $attributes): Application
    {
        return Application::query()->create($attributes);
    }

    public function update(Application $application, array $attributes): Application
    {
        $application->fill($attributes)->save();

        return $application;
    }

    public function recordStageChange(array $attributes): StageChange
    {
        return StageChange::query()->create($attributes);
    }

    public function bumpLastTouch(int $candidateId, ?int $applicationId, Carbon $at): void
    {
        Application::query()
            ->where('candidate_id', $candidateId)
            ->when(
                $applicationId,
                fn (Builder $q, int $id) => $q->whereKey($id),
                fn (Builder $q) => $q->where('status', ApplicationStatus::Active->value),
            )
            ->where(fn (Builder $q) => $q->whereNull('last_touch_at')->orWhere('last_touch_at', '<', $at))
            ->update(['last_touch_at' => $at]);
    }

    public function stale(Scope $scope, Carbon $before, int $limit): Collection
    {
        return Application::query()
            ->with(['candidate', 'vacancy', 'stage'])
            ->where('status', ApplicationStatus::Active->value)
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('vacancy', fn (Builder $v) => $v->whereIn('branch_id', $scope->branchIds ?? []))
                ->orWhereIn('vacancy_id', $scope->managedVacancyIds)
                ->orWhereIn('id', $scope->interviewApplicationIds)))
            ->whereRaw('coalesce(last_touch_at, created_at) < ?', [$before])
            ->orderByRaw('coalesce(last_touch_at, created_at) asc')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }
}
