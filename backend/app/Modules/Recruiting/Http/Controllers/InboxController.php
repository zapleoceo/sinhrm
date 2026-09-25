<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\InboxRequest;
use App\Modules\Recruiting\Http\Requests\PerPageRequest;
use App\Modules\Recruiting\Http\Resources\CandidateResource;
use App\Modules\Recruiting\Http\Resources\TouchpointResource;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Services\CandidateService;
use App\Modules\Recruiting\Services\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Unmatched messages (touchpoints without a candidate) in the user's scope. */
final class InboxController
{
    use Actor;

    public function __construct(private readonly InboxService $service) {}

    public function index(PerPageRequest $request): AnonymousResourceCollection
    {
        return TouchpointResource::collection($this->service->list($this->actor($request), $request->perPage()));
    }

    public function link(InboxRequest $request, Touchpoint $touchpoint): TouchpointResource
    {
        $candidate = $request->linkTarget();
        assert($candidate instanceof Candidate);

        return new TouchpointResource($this->service->link($touchpoint, $candidate));
    }

    public function createCandidate(InboxRequest $request, Touchpoint $touchpoint, CandidateService $candidates): JsonResponse
    {
        $candidate = $this->service->createCandidate(
            $this->actor($request),
            $touchpoint,
            $request->fullName(),
            $request->vacancyId(),
        );

        return (new CandidateResource($candidates->find($candidate->id)))->response()->setStatusCode(201);
    }
}
