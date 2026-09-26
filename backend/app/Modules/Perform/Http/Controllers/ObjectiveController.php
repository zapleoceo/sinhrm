<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Controllers;

use App\Modules\Perform\Http\Requests\CheckInRequest;
use App\Modules\Perform\Http\Requests\ListPerformRequest;
use App\Modules\Perform\Http\Requests\SaveObjectiveRequest;
use App\Modules\Perform\Http\Resources\ObjectiveResource;
use App\Modules\Perform\Models\Objective;
use App\Modules\Perform\Services\ObjectiveService;
use App\Modules\Perform\Services\PerformAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Objectives (OKR): flat list (the client builds the alignment tree by parent_objective_id), check-ins. */
final class ObjectiveController extends PerformController
{
    public function __construct(PerformAccess $access, private readonly ObjectiveService $objectives)
    {
        parent::__construct($access);
    }

    public function index(ListPerformRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);

        return new JsonResponse(['data' => $this->objectives->list($viewer, $request->period(), $request->ownerId())
            ->map(fn (Objective $o): array => ObjectiveResource::for($o, $this->objectives->canEdit($viewer, $o))->resolve())
            ->values()->all()]);
    }

    public function show(Request $request, int $id): ObjectiveResource
    {
        $viewer = $this->viewer($request);
        $objective = $this->objectives->findVisible($viewer, $id);

        return ObjectiveResource::for($objective, $this->objectives->canEdit($viewer, $objective), true);
    }

    public function store(SaveObjectiveRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $objective = $this->objectives->create($this->actor($request), $viewer, $request->payload());

        return ObjectiveResource::for($objective->load('owner:id,full_name'), true)->response()->setStatusCode(201);
    }

    public function update(SaveObjectiveRequest $request, int $id): ObjectiveResource
    {
        $viewer = $this->viewer($request);
        $objective = $this->objectives->update($viewer, $this->objectives->findVisible($viewer, $id), $request->payload());

        return ObjectiveResource::for($objective->load('owner:id,full_name'), true);
    }

    public function checkIn(CheckInRequest $request, int $id): ObjectiveResource
    {
        $viewer = $this->viewer($request);
        $objective = $this->objectives->checkIn(
            $this->actor($request),
            $viewer,
            $this->objectives->findVisible($viewer, $id),
            $request->values(),
            $request->comment(),
        );

        return ObjectiveResource::for($objective, true, true);
    }

    public function destroy(Request $request, int $id): Response
    {
        $viewer = $this->viewer($request);
        $this->objectives->delete($viewer, $this->objectives->findVisible($viewer, $id));

        return response()->noContent();
    }
}
