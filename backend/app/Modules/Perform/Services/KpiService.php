<?php

declare(strict_types=1);

namespace App\Modules\Perform\Services;

use App\Models\User;
use App\Modules\Perform\Contracts\KpiRepository;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Exceptions\PerformException;
use App\Modules\Perform\Models\Kpi;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * KPIs: a metric of one employee per period with target and actual. Reading: the employee, managers above, admins.
 * Writing (targets and actuals): managers above the employee and admins — the employee does not grade themself.
 */
final readonly class KpiService
{
    public const int LIMIT = 500;

    public function __construct(private KpiRepository $kpis) {}

    /** @return Collection<int, Kpi> */
    public function list(PerformViewer $viewer, ?int $employeeId, ?string $period): Collection
    {
        return $this->kpis->list($viewer->visibleIds(), $employeeId, $period, self::LIMIT);
    }

    /** @throws ModelNotFoundException<Kpi> */
    public function findVisible(PerformViewer $viewer, int $id): Kpi
    {
        $kpi = $this->kpis->find($id);
        if ($kpi === null || ! $viewer->sees($kpi->employee_id)) {
            throw (new ModelNotFoundException)->setModel(Kpi::class, [$id]);
        }

        return $kpi;
    }

    /**
     * @param  array{employee_id: int, metric: string, unit?: string|null, period: string, target: float|int|string, actual?: float|int|string|null}  $data
     *
     * @throws AuthorizationException|PerformException
     */
    public function save(User $actor, PerformViewer $viewer, ?Kpi $kpi, array $data): Kpi
    {
        if (! $viewer->manages($data['employee_id']) || ($kpi !== null && ! $viewer->manages($kpi->employee_id))) {
            throw new AuthorizationException;
        }
        if ($this->kpis->exists($data['employee_id'], $data['metric'], $data['period'], $kpi?->id)) {
            throw PerformException::duplicate();
        }

        return $this->kpis->save($kpi, $data + ($kpi === null ? ['created_by' => $actor->id] : []));
    }

    /** @throws AuthorizationException */
    public function delete(PerformViewer $viewer, Kpi $kpi): void
    {
        if (! $viewer->manages($kpi->employee_id)) {
            throw new AuthorizationException;
        }
        $this->kpis->delete($kpi);
    }
}
