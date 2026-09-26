<?php

declare(strict_types=1);

namespace App\Modules\Perform\Services;

use App\Modules\Perform\Contracts\ReviewRepository;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Enums\AssignmentStatus;
use App\Modules\Perform\Enums\CycleStatus;
use App\Modules\Perform\Exceptions\PerformException;
use App\Modules\Perform\Models\Competency;
use App\Modules\Perform\Models\ReviewAssignment;
use App\Modules\Perform\Models\ReviewCycle;
use App\Modules\Perform\Support\ReviewResults;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Reviewer side and results. A reviewer sees and fills only their own forms (anyone else → 404).
 * Results of a subject: admins and managers above the subject — any time; the subject — after the cycle is closed.
 * Peer/upward answers are aggregated by ReviewResults (only after the cycle is closed, minimum group, no names in
 * anonymous cycles; while active only a completion range);
 * no endpoint returns who gave which rating.
 */
final readonly class ReviewService
{
    public function __construct(private ReviewRepository $reviews) {}

    /** @return Collection<int, ReviewAssignment> */
    public function mine(PerformViewer $viewer): Collection
    {
        $self = $viewer->selfId();

        return $self === null ? new Collection : $this->reviews->assignmentsFor($self);
    }

    /** @throws ModelNotFoundException<ReviewAssignment> not the viewer's own form */
    public function findOwn(PerformViewer $viewer, int $id): ReviewAssignment
    {
        $assignment = $this->reviews->findAssignment($id);
        if ($assignment === null || ! $viewer->isSelf($assignment->reviewer_employee_id)
            || $assignment->cycle->status === CycleStatus::Draft) {
            throw (new ModelNotFoundException)->setModel(ReviewAssignment::class, [$id]);
        }

        return $assignment;
    }

    /** @return Collection<int, Competency> competencies of the cycle with scales, in cycle order */
    public function competenciesOf(ReviewCycle $cycle): Collection
    {
        return $this->reviews->competenciesByIds(array_map('intval', $cycle->competency_ids));
    }

    /**
     * Every competency of the cycle exactly once, rating from its scale; comments optional.
     *
     * @param  list<array{competency_id: int, rating: int, comment?: string|null}>  $answers
     *
     * @throws PerformException cycle_not_active | already_submitted | invalid_answers
     */
    public function submit(ReviewAssignment $assignment, array $answers): ReviewAssignment
    {
        if ($assignment->cycle->status !== CycleStatus::Active) {
            throw PerformException::cycleNotActive();
        }
        if ($assignment->status !== AssignmentStatus::Pending) {
            throw PerformException::alreadySubmitted();
        }
        $competencies = $this->competenciesOf($assignment->cycle)->keyBy('id');
        $rows = [];
        foreach ($answers as $answer) {
            $competency = $competencies->get($answer['competency_id']);
            if (! $competency instanceof Competency || isset($rows[$competency->id])
                || ! in_array($answer['rating'], $competency->scale->values(), true)) {
                throw PerformException::invalidAnswers();
            }
            $comment = trim((string) ($answer['comment'] ?? ''));
            $rows[$competency->id] = ['competency_id' => $competency->id, 'rating' => $answer['rating'], 'comment' => $comment === '' ? null : $comment];
        }
        if (count($rows) !== $competencies->count()) {
            throw PerformException::invalidAnswers();
        }
        if (! $this->reviews->submit($assignment, array_values($rows))) {
            throw PerformException::alreadySubmitted();
        }

        return $this->reviews->findAssignment($assignment->id) ?? $assignment;
    }

    public function canSeeResults(PerformViewer $viewer, ReviewCycle $cycle, int $subjectId): bool
    {
        return $viewer->manages($subjectId) || ($viewer->isSelf($subjectId) && $cycle->status === CycleStatus::Closed);
    }

    /**
     * @return array<string, mixed> ReviewResults::aggregate + cycle and subject ids
     *
     * @throws ModelNotFoundException<ReviewCycle>
     */
    public function results(PerformViewer $viewer, ReviewCycle $cycle, int $subjectId): array
    {
        if ($cycle->status === CycleStatus::Draft || ! $this->canSeeResults($viewer, $cycle, $subjectId)) {
            throw (new ModelNotFoundException)->setModel(ReviewCycle::class, [$cycle->id]);
        }
        $competencies = $this->competenciesOf($cycle)->map(static fn (Competency $c): array => [
            'id' => $c->id, 'name' => $c->name, 'max' => $c->scale->maxValue(),
        ])->values()->all();

        return [
            'cycle' => ['id' => $cycle->id, 'name' => $cycle->name, 'status' => $cycle->status->value, 'anonymous' => $cycle->anonymous],
            'subject_employee_id' => $subjectId,
            'min_reviewers' => ReviewResults::MIN_REVIEWERS,
        ] + ReviewResults::aggregate($cycle->reviewTypes(), $competencies, $this->reviews->submittedRows($cycle->id, $subjectId), $cycle->anonymous, $cycle->status === CycleStatus::Closed);
    }

    /**
     * Results of every cycle about this employee the viewer may see (the profile's Performance tab).
     *
     * @return list<array<string, mixed>>
     */
    public function resultsFor(PerformViewer $viewer, int $subjectId): array
    {
        $out = [];
        foreach ($this->reviews->cyclesAbout($subjectId) as $cycle) {
            if ($this->canSeeResults($viewer, $cycle, $subjectId)) {
                $out[] = $this->results($viewer, $cycle, $subjectId);
            }
        }

        return $out;
    }
}
