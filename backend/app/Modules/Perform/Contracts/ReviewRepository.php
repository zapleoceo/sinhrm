<?php

declare(strict_types=1);

namespace App\Modules\Perform\Contracts;

use App\Modules\People\Models\Employee;
use App\Modules\Perform\Models\Competency;
use App\Modules\Perform\Models\RatingScale;
use App\Modules\Perform\Models\ReviewAssignment;
use App\Modules\Perform\Models\ReviewCycle;
use Illuminate\Database\Eloquent\Collection;

/** Rating scales, competencies, review cycles, assignments and answers. */
interface ReviewRepository
{
    /** @return Collection<int, RatingScale> */
    public function scales(): Collection;

    public function findScale(int $id): ?RatingScale;

    /** @param  array<string, mixed>  $attributes */
    public function saveScale(?RatingScale $scale, array $attributes): RatingScale;

    public function scaleInUse(RatingScale $scale): bool;

    public function deleteScale(RatingScale $scale): void;

    /** @return Collection<int, Competency> with scale */
    public function competencies(bool $activeOnly = false): Collection;

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Competency> with scale, in the order of $ids
     */
    public function competenciesByIds(array $ids): Collection;

    public function findCompetency(int $id): ?Competency;

    /** @param  array<string, mixed>  $attributes */
    public function saveCompetency(?Competency $competency, array $attributes): Competency;

    public function competencyInUse(Competency $competency): bool;

    public function deleteCompetency(Competency $competency): void;

    /** @return Collection<int, ReviewCycle> newest first, with assignments_count / submitted_count */
    public function cycles(): Collection;

    public function findCycle(int $id): ?ReviewCycle;

    /** @param  array<string, mixed>  $attributes */
    public function saveCycle(?ReviewCycle $cycle, array $attributes): ReviewCycle;

    public function deleteCycle(ReviewCycle $cycle): void;

    /**
     * Not terminated employees matching the participant filter (empty lists = no restriction).
     *
     * @param  list<int>  $branchIds
     * @param  list<int>  $departmentIds
     * @return Collection<int, Employee>
     */
    public function participants(array $branchIds, array $departmentIds): Collection;

    /**
     * Not terminated direct reports of these managers.
     *
     * @param  list<int>  $managerIds
     * @return Collection<int, Employee>
     */
    public function workingReportsOf(array $managerIds): Collection;

    /** Creates the assignment once (unique cycle/subject/reviewer/type); true when it was new. */
    public function assignOnce(int $cycleId, int $subjectId, int $reviewerId, string $type): bool;

    /** @return Collection<int, ReviewAssignment> of the cycle with subject and reviewer */
    public function assignmentsOf(ReviewCycle $cycle): Collection;

    /** @return Collection<int, ReviewAssignment> of the reviewer in active/closed cycles, with cycle and subject */
    public function assignmentsFor(int $reviewerId): Collection;

    public function findAssignment(int $id): ?ReviewAssignment;

    /**
     * Stores the answers and marks the assignment submitted, once (atomic).
     *
     * @param  list<array{competency_id: int, rating: int, comment: string|null}>  $answers
     */
    public function submit(ReviewAssignment $assignment, array $answers): bool;

    /**
     * Submitted answers about one subject in a cycle, flattened for ReviewResults.
     *
     * @return list<array{type: string, reviewer_id: int, reviewer_name: string, competency_id: int, rating: int, comment: string|null}>
     */
    public function submittedRows(int $cycleId, int $subjectId): array;

    /**
     * Who submitted about the subject in each cycle, per reviewer type (ids only, no answers) — for the
     * differencing guard between cycles.
     *
     * @param  list<int>  $cycleIds
     * @return array<int, array<string, list<int>>> cycle id to type to reviewer ids
     */
    public function submittedReviewers(array $cycleIds, int $subjectId): array;

    /** @return Collection<int, ReviewCycle> active/closed cycles where the employee is a subject */
    public function cyclesAbout(int $subjectId): Collection;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
