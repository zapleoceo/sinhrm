<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\CareerSubmission;
use App\Modules\Recruiting\Models\Offer;
use App\Modules\Recruiting\Models\StageChange;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface ApplicationRepository
{
    /** Days without contact after which an active application is stale (board highlight, /stale, home page). */
    public const int STALE_DAYS = 3;

    public function find(int $id): ?Application;

    public function findFor(int $candidateId, int $vacancyId): ?Application;

    /** Most recently updated active application of the candidate (for attaching captured messages). */
    public function latestActiveFor(int $candidateId): ?Application;

    /** @return Collection<int, Application> with vacancy, stage, reject reason and the route (stage changes) */
    public function forCandidate(int $candidateId, Scope $scope): Collection;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Application;

    /** @param  array<string, mixed>  $attributes */
    public function update(Application $application, array $attributes): Application;

    /** @param  array<string, mixed>  $attributes */
    public function recordStageChange(array $attributes): StageChange;

    /** The application is in the user's recruiting scope (ApplicationVisibility: branch, hiring manager, interviewer). */
    public function isVisible(int $applicationId, Scope $scope): bool;

    /** Newest career-site submission of the application that carries a CV (with the body), or null. */
    public function latestCv(int $applicationId): ?CareerSubmission;

    /** The offer of the application (at most one). */
    public function offerFor(int $applicationId): ?Offer;

    /** @param  array<string, mixed>  $attributes */
    public function createOffer(array $attributes): Offer;

    /** @param  array<string, mixed>  $attributes */
    public function updateOffer(Offer $offer, array $attributes): void;

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
