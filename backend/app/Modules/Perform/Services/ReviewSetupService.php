<?php

declare(strict_types=1);

namespace App\Modules\Perform\Services;

use App\Models\User;
use App\Modules\People\Models\Employee;
use App\Modules\Perform\Contracts\ReviewRepository;
use App\Modules\Perform\Enums\CycleStatus;
use App\Modules\Perform\Enums\ReviewType;
use App\Modules\Perform\Exceptions\PerformException;
use App\Modules\Perform\Models\Competency;
use App\Modules\Perform\Models\RatingScale;
use App\Modules\Perform\Models\ReviewAssignment;
use App\Modules\Perform\Models\ReviewCycle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * Admin side of reviews: rating scales, competencies, cycles (draft → active → closed).
 * Activation creates the assignments from the participant filter and the cycle types:
 * self — the participant; manager — their manager; peer — up to PEERS colleagues with the same manager;
 * upward — their direct reports. Only not terminated people take part. An admin may add a peer by hand.
 */
final readonly class ReviewSetupService
{
    public const int PEERS = 5;

    public function __construct(private ReviewRepository $reviews) {}

    /** @return Collection<int, RatingScale> */
    public function scales(): Collection
    {
        return $this->reviews->scales();
    }

    public function findScale(int $id): RatingScale
    {
        return $this->reviews->findScale($id) ?? throw (new ModelNotFoundException)->setModel(RatingScale::class, [$id]);
    }

    /** @param  array{name: string, levels: list<array{value: int, label: string}>}  $data */
    public function saveScale(?RatingScale $scale, array $data): RatingScale
    {
        $levels = $data['levels'];
        usort($levels, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);

        return $this->reviews->saveScale($scale, ['name' => $data['name'], 'levels' => $levels]);
    }

    /** @throws PerformException in_use */
    public function deleteScale(RatingScale $scale): void
    {
        if ($this->reviews->scaleInUse($scale)) {
            throw PerformException::inUse();
        }
        $this->reviews->deleteScale($scale);
    }

    /** @return Collection<int, Competency> */
    public function competencies(): Collection
    {
        return $this->reviews->competencies();
    }

    public function findCompetency(int $id): Competency
    {
        return $this->reviews->findCompetency($id) ?? throw (new ModelNotFoundException)->setModel(Competency::class, [$id]);
    }

    /** @param  array{name: string, description?: string|null, scale_id: int, active?: bool}  $data */
    public function saveCompetency(?Competency $competency, array $data): Competency
    {
        return $this->reviews->saveCompetency($competency, $data);
    }

    /** @throws PerformException in_use */
    public function deleteCompetency(Competency $competency): void
    {
        if ($this->reviews->competencyInUse($competency)) {
            throw PerformException::inUse();
        }
        $this->reviews->deleteCompetency($competency);
    }

    /** @return Collection<int, ReviewCycle> */
    public function cycles(): Collection
    {
        return $this->reviews->cycles();
    }

    public function findCycle(int $id): ReviewCycle
    {
        return $this->reviews->findCycle($id) ?? throw (new ModelNotFoundException)->setModel(ReviewCycle::class, [$id]);
    }

    /** @return Collection<int, ReviewAssignment> who reviews whom (statuses only, never answers) */
    public function assignments(ReviewCycle $cycle): Collection
    {
        return $this->reviews->assignmentsOf($cycle);
    }

    /**
     * Create or edit a draft cycle.
     *
     * @param  array<string, mixed>  $data  validated
     *
     * @throws PerformException cycle_not_draft
     */
    public function saveCycle(User $actor, ?ReviewCycle $cycle, array $data): ReviewCycle
    {
        if ($cycle !== null && $cycle->status !== CycleStatus::Draft) {
            throw PerformException::cycleNotDraft();
        }

        return $this->reviews->saveCycle($cycle, $data + ($cycle === null ? ['created_by' => $actor->id] : []));
    }

    /** @throws PerformException cycle_not_draft */
    public function deleteCycle(ReviewCycle $cycle): void
    {
        if ($cycle->status !== CycleStatus::Draft) {
            throw PerformException::cycleNotDraft();
        }
        $this->reviews->deleteCycle($cycle);
    }

    /**
     * Draft → active: creates the assignments (idempotent thanks to the unique key).
     *
     * @throws PerformException cycle_not_draft
     */
    public function activate(ReviewCycle $cycle, ?Carbon $now = null): ReviewCycle
    {
        if ($cycle->status !== CycleStatus::Draft) {
            throw PerformException::cycleNotDraft();
        }
        $this->reviews->transaction(function () use ($cycle, $now): void {
            foreach ($this->plan($cycle) as [$subject, $reviewer, $type]) {
                $this->reviews->assignOnce($cycle->id, $subject, $reviewer, $type->value);
            }
            $this->reviews->saveCycle($cycle, ['status' => CycleStatus::Active->value, 'activated_at' => $now ?? Carbon::now()]);
        });

        return $this->findCycle($cycle->id);
    }

    /** @throws PerformException cycle_not_active */
    public function close(ReviewCycle $cycle, ?Carbon $now = null): ReviewCycle
    {
        if ($cycle->status !== CycleStatus::Active) {
            throw PerformException::cycleNotActive();
        }

        return $this->reviews->saveCycle($cycle, ['status' => CycleStatus::Closed->value, 'closed_at' => $now ?? Carbon::now()]);
    }

    /**
     * Adds one reviewer by hand (e.g. a peer from another team) to an active cycle.
     *
     * @throws PerformException cycle_not_active | self_target | duplicate
     */
    public function addAssignment(ReviewCycle $cycle, int $subjectId, int $reviewerId, ReviewType $type): void
    {
        if ($cycle->status !== CycleStatus::Active) {
            throw PerformException::cycleNotActive();
        }
        if (($type === ReviewType::Self) !== ($subjectId === $reviewerId)) {
            throw PerformException::selfTarget();
        }
        if (! $this->reviews->assignOnce($cycle->id, $subjectId, $reviewerId, $type->value)) {
            throw PerformException::duplicate();
        }
    }

    /** @return list<array{0: int, 1: int, 2: ReviewType}> subject, reviewer, type */
    private function plan(ReviewCycle $cycle): array
    {
        $participants = $this->reviews->participants(
            array_map('intval', $cycle->participants['branch_ids'] ?? []),
            array_map('intval', $cycle->participants['department_ids'] ?? []),
        );
        $working = $this->reviews->participants([], [])->keyBy('id');
        $types = $cycle->reviewTypes();
        $byManager = $working->groupBy('manager_id');
        $upward = in_array(ReviewType::Upward, $types, true)
            ? $this->reviews->workingReportsOf($participants->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all())->groupBy('manager_id')
            : collect();

        $plan = [];
        foreach ($participants as $p) {
            foreach ($types as $type) {
                $reviewers = match ($type) {
                    ReviewType::Self => [$p->id],
                    ReviewType::Manager => $p->manager_id !== null && $working->has($p->manager_id) ? [$p->manager_id] : [],
                    ReviewType::Peer => $p->manager_id === null ? [] : $byManager->get($p->manager_id, collect())
                        ->reject(static fn (Employee $e): bool => $e->id === $p->id)->take(self::PEERS)->pluck('id')->all(),
                    ReviewType::Upward => $upward->get($p->id, collect())->pluck('id')->all(),
                };
                foreach ($reviewers as $reviewerId) {
                    $plan[] = [$p->id, (int) $reviewerId, $type];
                }
            }
        }

        return $plan;
    }
}
