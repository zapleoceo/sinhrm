<?php

declare(strict_types=1);

namespace App\Modules\Perform\Services;

use App\Modules\Core\Support\MembershipDifferencing;
use App\Modules\Perform\Contracts\ReviewRepository;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Enums\AssignmentStatus;
use App\Modules\Perform\Enums\CycleStatus;
use App\Modules\Perform\Enums\ReviewType;
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
 * anonymous cycles; while active only a completion range). Between cycles (differencing guard): a peer/upward group
 * of a subject is hidden in a later cycle when its reviewers differ by 1..MIN_REVIEWERS−1 people from an earlier
 * cycle in which the same group was shown (MembershipDifferencing) — "4 raters now minus 3 raters then" would
 * otherwise give the added rater's scores;
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
        ] + $this->guarded($cycle, $subjectId, $competencies);
    }

    /**
     * ReviewResults::aggregate with the differencing guard between cycles applied to protected groups.
     *
     * @param  list<array{id: int, name: string, max: int}>  $competencies
     * @return array<string, mixed>
     */
    private function guarded(ReviewCycle $cycle, int $subjectId, array $competencies): array
    {
        $rows = $this->reviews->submittedRows($cycle->id, $subjectId);
        $final = $cycle->status === CycleStatus::Closed;
        $hidden = $final ? $this->hiddenTypes($cycle, $subjectId) : [];
        $rows = array_values(array_filter($rows, static fn (array $r): bool => ! in_array($r['type'], $hidden, true)));
        $out = ReviewResults::aggregate($cycle->reviewTypes(), $competencies, $rows, $cycle->anonymous, $final);
        foreach ($hidden as $type) {
            if (isset($out['groups'][$type])) {
                $out['groups'][$type] = ['reviewers' => null, 'suppressed' => true, 'hidden_reason' => 'anonymity'];
            }
        }

        return $out;
    }

    /**
     * Protected types of this (closed) cycle hidden by the differencing guard against the subject's earlier
     * closed cycles (ordered by closing time). Only groups actually shown count as a base (>= MIN_REVIEWERS).
     *
     * @return list<string>
     */
    private function hiddenTypes(ReviewCycle $cycle, int $subjectId): array
    {
        $order = static fn (ReviewCycle $c): array => [$c->closed_at?->getTimestamp() ?? 0, $c->id];
        $series = $this->reviews->cyclesAbout($subjectId)
            ->filter(static fn (ReviewCycle $c): bool => $c->status === CycleStatus::Closed && $order($c) < $order($cycle))
            ->sortBy($order)->values()->all();
        $series[] = $cycle;
        $reviewers = $this->reviews->submittedReviewers(array_map(static fn (ReviewCycle $c): int => $c->id, $series), $subjectId);
        $releases = [];
        foreach ($series as $c) {
            $groups = [];
            foreach ($reviewers[$c->id] ?? [] as $type => $ids) {
                if (ReviewType::from($type)->isProtected() && count($ids) >= ReviewResults::MIN_REVIEWERS) {
                    $groups[$type] = $ids;
                }
            }
            $releases[] = ['min' => ReviewResults::MIN_REVIEWERS, 'groups' => $groups];
        }
        $visible = MembershipDifferencing::visibility($releases);

        return array_keys(array_filter($visible[array_key_last($visible)], static fn (bool $v): bool => ! $v));
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
