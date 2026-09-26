<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Controllers;

use App\Modules\Perform\Http\Requests\SubmitReviewRequest;
use App\Modules\Perform\Http\Resources\AssignmentResource;
use App\Modules\Perform\Models\ReviewAssignment;
use App\Modules\Perform\Services\PerformAccess;
use App\Modules\Perform\Services\ReviewService;
use App\Modules\Perform\Services\ReviewSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reviewer forms ("Мої оцінювання") and aggregated results. Rules — ReviewService. */
final class ReviewController extends PerformController
{
    public function __construct(
        PerformAccess $access,
        private readonly ReviewService $reviews,
        private readonly ReviewSetupService $setup,
    ) {
        parent::__construct($access);
    }

    public function mine(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->reviews->mine($this->viewer($request))
            ->map(static fn (ReviewAssignment $a): array => AssignmentResource::for($a)->resolve())->values()->all()]);
    }

    public function show(Request $request, int $id): AssignmentResource
    {
        $assignment = $this->reviews->findOwn($this->viewer($request), $id);

        return AssignmentResource::for($assignment, $this->reviews->competenciesOf($assignment->cycle));
    }

    public function submit(SubmitReviewRequest $request, int $id): AssignmentResource
    {
        $assignment = $this->reviews->submit($this->reviews->findOwn($this->viewer($request), $id), $request->answers());

        return AssignmentResource::for($assignment, $this->reviews->competenciesOf($assignment->cycle));
    }

    /** Results of one subject in one cycle. */
    public function results(Request $request, int $cycleId, int $employeeId): JsonResponse
    {
        return new JsonResponse(['data' => $this->reviews->results($this->viewer($request), $this->setup->findCycle($cycleId), $employeeId)]);
    }

    /** Results of every visible cycle about an employee (profile tab). */
    public function employeeResults(Request $request, int $employeeId): JsonResponse
    {
        return new JsonResponse(['data' => $this->reviews->resultsFor($this->viewer($request), $employeeId)]);
    }
}
