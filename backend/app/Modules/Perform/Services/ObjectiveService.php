<?php

declare(strict_types=1);

namespace App\Modules\Perform\Services;

use App\Models\User;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\Perform\Contracts\ObjectiveRepository;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Enums\ObjectiveScope;
use App\Modules\Perform\Enums\ObjectiveVisibility;
use App\Modules\Perform\Exceptions\PerformException;
use App\Modules\Perform\Models\Objective;
use App\Modules\Perform\Support\ListItems;
use App\Modules\Perform\Support\ObjectiveProgress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Objectives (OKR). Reading: public — everyone; team — the owner's department, the owner and managers above them;
 * private — the owner and managers above them; admins — everything.
 * Writing (edit, check-in, delete): the owner, managers above the owner, admins. Objectives without an owner
 * (branch / company level) and company scope: admins only. Progress is always recomputed from key results.
 */
final readonly class ObjectiveService
{
    public const int LIMIT = 500;

    /** Alignment chains longer than this are treated as a loop (bad data guard). */
    private const int MAX_DEPTH = 20;

    private const array KEY_RESULT = ['title' => '', 'start' => 0, 'target' => 100, 'current' => 0, 'unit' => null, 'weight' => 1];

    public function __construct(private ObjectiveRepository $objectives, private EmployeeRepository $employees) {}

    /** @return Collection<int, Objective> */
    public function list(PerformViewer $viewer, ?string $period, ?int $ownerId): Collection
    {
        return $this->objectives->visible($viewer, $period, $ownerId, self::LIMIT);
    }

    public function canView(PerformViewer $viewer, Objective $objective): bool
    {
        if ($viewer->admin() || $objective->visibility === ObjectiveVisibility::Public) {
            return true;
        }
        if ($objective->owner_employee_id !== null && $viewer->sees($objective->owner_employee_id)) {
            return true;
        }

        return $objective->visibility === ObjectiveVisibility::Team
            && $viewer->departmentId !== null && $objective->department_id === $viewer->departmentId;
    }

    public function canEdit(PerformViewer $viewer, Objective $objective): bool
    {
        return $viewer->admin() || ($objective->owner_employee_id !== null && $viewer->sees($objective->owner_employee_id)
            && $objective->scope !== ObjectiveScope::Company);
    }

    /** @throws ModelNotFoundException<Objective> */
    public function findVisible(PerformViewer $viewer, int $id): Objective
    {
        $objective = $this->objectives->find($id);
        if ($objective === null || ! $this->canView($viewer, $objective)) {
            throw (new ModelNotFoundException)->setModel(Objective::class, [$id]);
        }

        return $objective;
    }

    /**
     * @param  array<string, mixed>  $data  validated: scope, owner_employee_id?, department_id?, branch_id?, period, title, description?, key_results, status?, parent_objective_id?, visibility
     *
     * @throws AuthorizationException|PerformException
     */
    public function create(User $actor, PerformViewer $viewer, array $data): Objective
    {
        $attributes = $this->attributes($viewer, $data, null);

        return $this->objectives->create($attributes + ['created_by' => $actor->id]);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException|PerformException
     */
    public function update(PerformViewer $viewer, Objective $objective, array $data): Objective
    {
        if (! $this->canEdit($viewer, $objective)) {
            throw new AuthorizationException;
        }

        return $this->objectives->update($objective, $this->attributes($viewer, $data, $objective));
    }

    /**
     * Records new current values of key results (by id), recomputes progress and keeps the history.
     *
     * @param  list<array{id: string, current: float|int}>  $values
     *
     * @throws AuthorizationException
     */
    public function checkIn(User $actor, PerformViewer $viewer, Objective $objective, array $values, ?string $comment): Objective
    {
        if (! $this->canEdit($viewer, $objective)) {
            throw new AuthorizationException;
        }
        $current = [];
        foreach ($values as $value) {
            $current[$value['id']] = $value['current'];
        }
        $keyResults = array_map(static function (array $kr) use ($current): array {
            if (array_key_exists((string) $kr['id'], $current)) {
                $kr['current'] = $current[(string) $kr['id']];
            }

            return $kr;
        }, $objective->key_results);
        $before = $objective->progress;
        $after = ObjectiveProgress::of($keyResults);

        $this->objectives->transaction(function () use ($objective, $keyResults, $after, $before, $actor, $comment): void {
            $this->objectives->update($objective, ['key_results' => $keyResults, 'progress' => $after]);
            $this->objectives->addCheckin([
                'objective_id' => $objective->id,
                'author_id' => $actor->id,
                'progress_before' => $before,
                'progress_after' => $after,
                'key_results' => array_map(static fn (array $kr): array => ['id' => $kr['id'], 'current' => $kr['current']], $keyResults),
                'comment' => $comment,
            ]);
        });

        return $this->objectives->find($objective->id) ?? $objective;
    }

    /** @throws AuthorizationException */
    public function delete(PerformViewer $viewer, Objective $objective): void
    {
        if (! $this->canEdit($viewer, $objective)) {
            throw new AuthorizationException;
        }
        $this->objectives->delete($objective);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws AuthorizationException|PerformException
     */
    private function attributes(PerformViewer $viewer, array $data, ?Objective $current): array
    {
        $scope = ObjectiveScope::from((string) $data['scope']);
        $ownerId = isset($data['owner_employee_id']) ? (int) $data['owner_employee_id'] : null;
        if ($ownerId === null && $scope === ObjectiveScope::Personal) {
            $ownerId = $viewer->selfId() ?? throw PerformException::noEmployee();
        }
        // Only admins set company goals and goals without an owner; others write for themselves or people below.
        if (! $viewer->admin() && ($scope === ObjectiveScope::Company || $ownerId === null || ! $viewer->sees($ownerId))) {
            throw new AuthorizationException;
        }
        $owner = $ownerId === null ? null : $this->employees->find($ownerId);
        $departmentId = $viewer->admin() && isset($data['department_id']) ? (int) $data['department_id'] : $owner?->department_id;
        $branchId = $viewer->admin() && isset($data['branch_id']) ? (int) $data['branch_id'] : $owner?->branch_id;

        $parentId = isset($data['parent_objective_id']) ? (int) $data['parent_objective_id'] : null;
        if ($parentId !== null) {
            $this->assertAlignable($viewer, $parentId, $current?->id);
        }
        $keyResults = ListItems::normalize(array_values((array) $data['key_results']), self::KEY_RESULT);

        return [
            'scope' => $scope->value,
            'owner_employee_id' => $ownerId,
            'department_id' => $departmentId,
            'branch_id' => $branchId,
            'period' => $data['period'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'key_results' => $keyResults,
            'progress' => ObjectiveProgress::of($keyResults),
            'status' => $data['status'] ?? ($current?->status->value ?? 'active'),
            'parent_objective_id' => $parentId,
            'visibility' => $data['visibility'] ?? ObjectiveVisibility::Public->value,
        ];
    }

    /** The parent must be visible and must not be the objective itself or below it. */
    private function assertAlignable(PerformViewer $viewer, int $parentId, ?int $selfId): void
    {
        $this->findVisible($viewer, $parentId);
        $node = $parentId;
        for ($depth = 0; $node !== null; $depth++) {
            if ($node === $selfId || $depth > self::MAX_DEPTH) {
                throw PerformException::alignmentCycle();
            }
            $node = $this->objectives->parentOf($node);
        }
    }
}
