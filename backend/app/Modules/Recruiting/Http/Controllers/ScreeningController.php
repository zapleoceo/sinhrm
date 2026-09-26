<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\ScreenApplicationRequest;
use App\Modules\Recruiting\Http\Resources\ScreeningResource;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Services\ScreeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** AI screening in the candidate card (tz6). Refusals of the Ai module render as {code} (ai_disabled, ai_budget_exceeded…). */
final class ScreeningController
{
    use Actor;

    public function __construct(private readonly ScreeningService $service) {}

    /** GET /candidates/{candidate}/screenings — visible with the candidate. */
    public function index(Request $request, Candidate $candidate): AnonymousResourceCollection
    {
        if (! $this->actor($request)->can('view', $candidate)) {
            throw new AccessDeniedHttpException;
        }

        return ScreeningResource::collection($this->service->forCandidate($candidate));
    }

    /** POST /applications/{application}/screening — 201 done or 202 still running (finished by ai.poll). */
    public function store(ScreenApplicationRequest $request, Application $application): JsonResponse
    {
        $screening = $this->service->start($application, $this->actor($request), 'manual');
        $screening->loadMissing('vacancy:id,title');

        return (new ScreeningResource($screening))->response()->setStatusCode($screening->status === 'pending' ? 202 : 201);
    }
}
