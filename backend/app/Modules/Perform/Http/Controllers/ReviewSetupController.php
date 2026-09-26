<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Controllers;

use App\Modules\Perform\Http\Requests\AddAssignmentRequest;
use App\Modules\Perform\Http\Requests\SaveCompetencyRequest;
use App\Modules\Perform\Http\Requests\SaveCycleRequest;
use App\Modules\Perform\Http\Requests\SaveScaleRequest;
use App\Modules\Perform\Http\Resources\ReviewCycleResource;
use App\Modules\Perform\Models\ReviewCycle;
use App\Modules\Perform\Services\PerformAccess;
use App\Modules\Perform\Services\ReviewSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Admin side of reviews (route gate perform-manage): scales, competencies, cycles and their assignments. */
final class ReviewSetupController extends PerformController
{
    public function __construct(PerformAccess $access, private readonly ReviewSetupService $setup)
    {
        parent::__construct($access);
    }

    public function scales(): JsonResponse
    {
        return new JsonResponse(['data' => $this->setup->scales()->values()]);
    }

    public function storeScale(SaveScaleRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->setup->saveScale(null, $request->payload())], 201);
    }

    public function updateScale(SaveScaleRequest $request, int $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->setup->saveScale($this->setup->findScale($id), $request->payload())]);
    }

    public function destroyScale(int $id): Response
    {
        $this->setup->deleteScale($this->setup->findScale($id));

        return response()->noContent();
    }

    public function competencies(): JsonResponse
    {
        return new JsonResponse(['data' => $this->setup->competencies()->values()]);
    }

    public function storeCompetency(SaveCompetencyRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->setup->saveCompetency(null, $request->payload())], 201);
    }

    public function updateCompetency(SaveCompetencyRequest $request, int $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->setup->saveCompetency($this->setup->findCompetency($id), $request->payload())]);
    }

    public function destroyCompetency(int $id): Response
    {
        $this->setup->deleteCompetency($this->setup->findCompetency($id));

        return response()->noContent();
    }

    public function cycles(): JsonResponse
    {
        return new JsonResponse(['data' => $this->setup->cycles()
            ->map(static fn (ReviewCycle $c): array => ReviewCycleResource::for($c)->resolve())->values()->all()]);
    }

    public function showCycle(int $id): ReviewCycleResource
    {
        return $this->withAssignments($this->setup->findCycle($id));
    }

    public function storeCycle(SaveCycleRequest $request): JsonResponse
    {
        $cycle = $this->setup->saveCycle($this->actor($request), null, $request->payload());

        return ReviewCycleResource::for($cycle)->response()->setStatusCode(201);
    }

    public function updateCycle(SaveCycleRequest $request, int $id): ReviewCycleResource
    {
        return ReviewCycleResource::for($this->setup->saveCycle($this->actor($request), $this->setup->findCycle($id), $request->payload()));
    }

    public function destroyCycle(int $id): Response
    {
        $this->setup->deleteCycle($this->setup->findCycle($id));

        return response()->noContent();
    }

    public function activate(int $id): ReviewCycleResource
    {
        return $this->withAssignments($this->setup->activate($this->setup->findCycle($id)));
    }

    public function close(int $id): ReviewCycleResource
    {
        return ReviewCycleResource::for($this->setup->close($this->setup->findCycle($id)));
    }

    public function addAssignment(AddAssignmentRequest $request, int $id): JsonResponse
    {
        $cycle = $this->setup->findCycle($id);
        $this->setup->addAssignment($cycle, $request->subjectId(), $request->reviewerId(), $request->type());

        return $this->withAssignments($this->setup->findCycle($id))->response()->setStatusCode(201);
    }

    private function withAssignments(ReviewCycle $cycle): ReviewCycleResource
    {
        return ReviewCycleResource::for($cycle, $this->setup->assignments($cycle));
    }
}
