<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Controllers;

use App\Modules\Perform\Http\Requests\ListPerformRequest;
use App\Modules\Perform\Http\Requests\SaveKpiRequest;
use App\Modules\Perform\Http\Resources\KpiResource;
use App\Modules\Perform\Models\Kpi;
use App\Modules\Perform\Services\KpiService;
use App\Modules\Perform\Services\PerformAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** KPIs per employee and period. Rules — KpiService. */
final class KpiController extends PerformController
{
    public function __construct(PerformAccess $access, private readonly KpiService $kpis)
    {
        parent::__construct($access);
    }

    public function index(ListPerformRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);

        return new JsonResponse(['data' => $this->kpis->list($viewer, $request->employeeId(), $request->period())
            ->map(static fn (Kpi $k): array => KpiResource::for($k, $viewer->manages($k->employee_id))->resolve())
            ->values()->all()]);
    }

    public function store(SaveKpiRequest $request): JsonResponse
    {
        $kpi = $this->kpis->save($this->actor($request), $this->viewer($request), null, $request->payload());

        return KpiResource::for($kpi, true)->response()->setStatusCode(201);
    }

    public function update(SaveKpiRequest $request, int $id): KpiResource
    {
        $viewer = $this->viewer($request);

        return KpiResource::for($this->kpis->save($this->actor($request), $viewer, $this->kpis->findVisible($viewer, $id), $request->payload()), true);
    }

    public function destroy(Request $request, int $id): Response
    {
        $viewer = $this->viewer($request);
        $this->kpis->delete($viewer, $this->kpis->findVisible($viewer, $id));

        return response()->noContent();
    }
}
