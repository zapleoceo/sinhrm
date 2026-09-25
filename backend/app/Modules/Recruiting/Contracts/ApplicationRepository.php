<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\StageChange;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface ApplicationRepository
{
    public function find(int $id): ?Application;

    public function findFor(int $candidateId, int $vacancyId): ?Application;

    /** Most recently updated active application of the candidate (for attaching captured messages). */
    public function latestActiveFor(int $candidateId): ?Application;

    /** @return Collection<int, Application> with vacancy, stage, reject reason and the route (stage changes) */
    public function forCandidate(int $candidateId): Collection;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Application;

    /** @param  array<string, mixed>  $attributes */
    public function update(Application $application, array $attributes): Application;

    /** @param  array<string, mixed>  $attributes */
    public function recordStageChange(array $attributes): StageChange;

    /** Moves last_touch_at forward (never back): one application, or all active ones of the candidate. */
    public function bumpLastTouch(int $candidateId, ?int $applicationId, Carbon $at): void;

    /**
     * Active applications in scope whose last contact (or creation, if never touched) is older than $before,
     * oldest first.
     *
     * @return Collection<int, Application> with candidate, vacancy, stage
     */
    public function stale(Scope $scope, Carbon $before, int $limit): Collection;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
