<?php

declare(strict_types=1);

namespace App\Modules\Perform\Services;

use App\Models\User;
use App\Modules\Perform\Contracts\DevelopmentPlanRepository;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Models\DevelopmentPlan;
use App\Modules\Perform\Support\ListItems;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Development plans. Reading: the employee, managers above, admins. Creating / editing / deleting: managers above
 * and admins. The employee ticks actions of their own plan as done.
 */
final readonly class DevelopmentPlanService
{
    public const int LIMIT = 200;

    private const array GOAL = ['text' => ''];

    private const array ACTION = ['text' => '', 'due_on' => null, 'done' => false];

    public function __construct(private DevelopmentPlanRepository $plans) {}

    /** @return Collection<int, DevelopmentPlan> */
    public function list(PerformViewer $viewer, ?int $employeeId): Collection
    {
        return $this->plans->list($viewer->visibleIds(), $employeeId, self::LIMIT);
    }

    /** @throws ModelNotFoundException<DevelopmentPlan> */
    public function findVisible(PerformViewer $viewer, int $id): DevelopmentPlan
    {
        $plan = $this->plans->find($id);
        if ($plan === null || ! $viewer->sees($plan->employee_id)) {
            throw (new ModelNotFoundException)->setModel(DevelopmentPlan::class, [$id]);
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $data  validated: employee_id, title, goals, actions, due_on?, status?
     *
     * @throws AuthorizationException
     */
    public function save(User $actor, PerformViewer $viewer, ?DevelopmentPlan $plan, array $data): DevelopmentPlan
    {
        $employeeId = (int) $data['employee_id'];
        if (! $viewer->manages($employeeId) || ($plan !== null && ! $viewer->manages($plan->employee_id))) {
            throw new AuthorizationException;
        }
        $attributes = [
            'employee_id' => $employeeId,
            'title' => $data['title'],
            'goals' => ListItems::normalize(array_values((array) ($data['goals'] ?? [])), self::GOAL),
            'actions' => ListItems::normalize(array_values((array) ($data['actions'] ?? [])), self::ACTION),
            'due_on' => $data['due_on'] ?? null,
            'status' => $data['status'] ?? ($plan?->status->value ?? 'active'),
        ];

        return $this->plans->save($plan, $attributes + ($plan === null ? ['created_by' => $actor->id] : []));
    }

    /** The employee (or a manager above / admin) marks one action done or not done. */
    public function toggleAction(PerformViewer $viewer, DevelopmentPlan $plan, string $actionId, bool $done): DevelopmentPlan
    {
        $found = false;
        $actions = array_map(static function (array $a) use ($actionId, $done, &$found): array {
            if ((string) $a['id'] === $actionId) {
                $a['done'] = $done;
                $found = true;
            }

            return $a;
        }, $plan->actions);
        if (! $found) {
            throw (new ModelNotFoundException)->setModel(DevelopmentPlan::class, [$plan->id]);
        }

        return $this->plans->save($plan, ['actions' => $actions]);
    }

    /** @throws AuthorizationException */
    public function delete(PerformViewer $viewer, DevelopmentPlan $plan): void
    {
        if (! $viewer->manages($plan->employee_id)) {
            throw new AuthorizationException;
        }
        $this->plans->delete($plan);
    }
}
