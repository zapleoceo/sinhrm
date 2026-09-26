<?php

declare(strict_types=1);

namespace App\Modules\Perform\Repositories;

use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Models\Employee;
use App\Modules\Perform\Contracts\ReviewRepository;
use App\Modules\Perform\Enums\AssignmentStatus;
use App\Modules\Perform\Enums\CycleStatus;
use App\Modules\Perform\Models\Competency;
use App\Modules\Perform\Models\RatingScale;
use App\Modules\Perform\Models\ReviewAnswer;
use App\Modules\Perform\Models\ReviewAssignment;
use App\Modules\Perform\Models\ReviewCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentReviewRepository implements ReviewRepository
{
    public function scales(): Collection
    {
        return RatingScale::query()->orderBy('name')->orderBy('id')->get();
    }

    public function findScale(int $id): ?RatingScale
    {
        return RatingScale::query()->find($id);
    }

    public function saveScale(?RatingScale $scale, array $attributes): RatingScale
    {
        $scale ??= new RatingScale;
        $scale->fill($attributes)->save();

        return $scale;
    }

    public function scaleInUse(RatingScale $scale): bool
    {
        return Competency::query()->where('scale_id', $scale->id)->exists();
    }

    public function deleteScale(RatingScale $scale): void
    {
        $scale->delete();
    }

    public function competencies(bool $activeOnly = false): Collection
    {
        return Competency::query()->with('scale')
            ->when($activeOnly, fn (Builder $q) => $q->where('active', true))
            ->orderBy('name')->orderBy('id')->get();
    }

    public function competenciesByIds(array $ids): Collection
    {
        $found = Competency::query()->with('scale')->whereIn('id', $ids)->get()->keyBy('id');

        return new Collection(array_values(array_filter(array_map(static fn (int $id): ?Competency => $found->get($id), $ids))));
    }

    public function findCompetency(int $id): ?Competency
    {
        return Competency::query()->with('scale')->find($id);
    }

    public function saveCompetency(?Competency $competency, array $attributes): Competency
    {
        $competency ??= new Competency;
        $competency->fill($attributes)->save();

        return $competency->load('scale');
    }

    public function competencyInUse(Competency $competency): bool
    {
        return ReviewAnswer::query()->where('competency_id', $competency->id)->exists()
            || ReviewCycle::query()->where('status', '!=', CycleStatus::Draft->value)->get(['competency_ids'])
                ->contains(static fn (ReviewCycle $c): bool => in_array($competency->id, $c->competency_ids, true));
    }

    public function deleteCompetency(Competency $competency): void
    {
        $competency->delete();
    }

    public function cycles(): Collection
    {
        return $this->withCounts(ReviewCycle::query())->orderByDesc('id')->get();
    }

    public function findCycle(int $id): ?ReviewCycle
    {
        return $this->withCounts(ReviewCycle::query())->find($id);
    }

    public function saveCycle(?ReviewCycle $cycle, array $attributes): ReviewCycle
    {
        $cycle ??= new ReviewCycle;
        $cycle->fill($attributes)->save();

        return $this->findCycle($cycle->id) ?? $cycle;
    }

    public function deleteCycle(ReviewCycle $cycle): void
    {
        $cycle->delete();
    }

    public function participants(array $branchIds, array $departmentIds): Collection
    {
        return Employee::query()->where('status', '!=', EmployeeStatus::Terminated->value)
            ->when($branchIds !== [], fn (Builder $q) => $q->whereIn('branch_id', $branchIds))
            ->when($departmentIds !== [], fn (Builder $q) => $q->whereIn('department_id', $departmentIds))
            ->orderBy('id')->get(['id', 'full_name', 'manager_id', 'branch_id', 'department_id']);
    }

    public function workingReportsOf(array $managerIds): Collection
    {
        return Employee::query()->where('status', '!=', EmployeeStatus::Terminated->value)
            ->whereIn('manager_id', $managerIds)->orderBy('id')->get(['id', 'full_name', 'manager_id']);
    }

    public function assignOnce(int $cycleId, int $subjectId, int $reviewerId, string $type): bool
    {
        return ReviewAssignment::query()->firstOrCreate([
            'cycle_id' => $cycleId,
            'subject_employee_id' => $subjectId,
            'reviewer_employee_id' => $reviewerId,
            'type' => $type,
        ])->wasRecentlyCreated;
    }

    public function assignmentsOf(ReviewCycle $cycle): Collection
    {
        return ReviewAssignment::query()->with(['subject:id,full_name', 'reviewer:id,full_name'])
            ->where('cycle_id', $cycle->id)->orderBy('subject_employee_id')->orderBy('type')->orderBy('id')->get();
    }

    public function assignmentsFor(int $reviewerId): Collection
    {
        return ReviewAssignment::query()->with(['cycle', 'subject:id,full_name'])
            ->where('reviewer_employee_id', $reviewerId)
            ->whereHas('cycle', fn (Builder $q) => $q->where('status', '!=', CycleStatus::Draft->value))
            ->orderBy('status')->orderByDesc('id')->limit(200)->get();
    }

    public function findAssignment(int $id): ?ReviewAssignment
    {
        return ReviewAssignment::query()->with(['cycle', 'subject:id,full_name', 'answers'])->find($id);
    }

    public function submit(ReviewAssignment $assignment, array $answers): bool
    {
        return DB::transaction(function () use ($assignment, $answers): bool {
            $claimed = ReviewAssignment::query()->whereKey($assignment->id)
                ->where('status', AssignmentStatus::Pending->value)
                ->update(['status' => AssignmentStatus::Submitted->value, 'submitted_at' => Carbon::now()]) === 1;
            if (! $claimed) {
                return false;
            }
            foreach ($answers as $answer) {
                ReviewAnswer::query()->create(['assignment_id' => $assignment->id] + $answer);
            }

            return true;
        });
    }

    public function submittedRows(int $cycleId, int $subjectId): array
    {
        return ReviewAnswer::query()
            ->join('review_assignments as a', 'a.id', '=', 'review_answers.assignment_id')
            ->join('employees as e', 'e.id', '=', 'a.reviewer_employee_id')
            ->where('a.cycle_id', $cycleId)->where('a.subject_employee_id', $subjectId)
            ->where('a.status', AssignmentStatus::Submitted->value)
            ->orderBy('review_answers.id')
            ->get(['a.type', 'a.reviewer_employee_id', 'e.full_name', 'review_answers.competency_id', 'review_answers.rating', 'review_answers.comment'])
            ->map(static fn (ReviewAnswer $row): array => [
                'type' => (string) $row->getAttribute('type'),
                'reviewer_id' => (int) $row->getAttribute('reviewer_employee_id'),
                'reviewer_name' => (string) $row->getAttribute('full_name'),
                'competency_id' => (int) $row->competency_id,
                'rating' => (int) $row->rating,
                'comment' => $row->comment,
            ])->values()->all();
    }

    public function submittedReviewers(array $cycleIds, int $subjectId): array
    {
        if ($cycleIds === []) {
            return [];
        }
        $out = [];
        $rows = ReviewAssignment::query()->whereIn('cycle_id', $cycleIds)->where('subject_employee_id', $subjectId)
            ->where('status', AssignmentStatus::Submitted->value)->orderBy('reviewer_employee_id')
            ->get(['cycle_id', 'type', 'reviewer_employee_id']);
        foreach ($rows as $row) {
            $out[$row->cycle_id][$row->type->value][] = $row->reviewer_employee_id;
        }

        return $out;
    }

    public function cyclesAbout(int $subjectId): Collection
    {
        return ReviewCycle::query()->where('status', '!=', CycleStatus::Draft->value)
            ->whereHas('assignments', fn (Builder $q) => $q->where('subject_employee_id', $subjectId))
            ->orderByDesc('id')->get();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }

    /**
     * @param  Builder<ReviewCycle>  $query
     * @return Builder<ReviewCycle>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'assignments',
            'assignments as submitted_count' => fn (Builder $q) => $q->where('status', AssignmentStatus::Submitted->value),
        ]);
    }
}
