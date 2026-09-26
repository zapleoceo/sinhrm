<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Controllers;

use App\Modules\Perform\Http\Requests\ListPerformRequest;
use App\Modules\Perform\Http\Requests\SavePlanRequest;
use App\Modules\Perform\Http\Requests\ToggleRequest;
use App\Modules\Perform\Http\Resources\DevelopmentPlanResource;
use App\Modules\Perform\Models\DevelopmentPlan;
use App\Modules\Perform\Services\DevelopmentPlanService;
use App\Modules\Perform\Services\PerformAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Development plans. Rules — DevelopmentPlanService. */
final class DevelopmentPlanController extends PerformController
{
    public function __construct(PerformAccess $access, private readonly DevelopmentPlanService $plans)
    {
        parent::__construct($access);
    }

    public function index(ListPerformRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);

        return new JsonResponse(['data' => $this->plans->list($viewer, $request->employeeId())
            ->map(static fn (DevelopmentPlan $p): array => DevelopmentPlanResource::for($p, $viewer->manages($p->employee_id))->resolve())
            ->values()->all()]);
    }

    public function store(SavePlanRequest $request): JsonResponse
    {
        $plan = $this->plans->save($this->actor($request), $this->viewer($request), null, $request->payload());

        return DevelopmentPlanResource::for($plan, true)->response()->setStatusCode(201);
    }

    public function update(SavePlanRequest $request, int $id): DevelopmentPlanResource
    {
        $viewer = $this->viewer($request);
        $plan = $this->plans->save($this->actor($request), $viewer, $this->plans->findVisible($viewer, $id), $request->payload());

        return DevelopmentPlanResource::for($plan, true);
    }

    public function toggleAction(ToggleRequest $request, int $id, string $actionId): DevelopmentPlanResource
    {
        $viewer = $this->viewer($request);
        $plan = $this->plans->toggleAction($viewer, $this->plans->findVisible($viewer, $id), $actionId, $request->done());

        return DevelopmentPlanResource::for($plan, $viewer->manages($plan->employee_id));
    }

    public function destroy(Request $request, int $id): Response
    {
        $viewer = $this->viewer($request);
        $this->plans->delete($viewer, $this->plans->findVisible($viewer, $id));

        return response()->noContent();
    }
}
