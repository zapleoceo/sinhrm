<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\ListCandidatesRequest;
use App\Modules\Recruiting\Http\Requests\LogTouchpointRequest;
use App\Modules\Recruiting\Http\Requests\SaveCandidateRequest;
use App\Modules\Recruiting\Http\Requests\TimelineRequest;
use App\Modules\Recruiting\Http\Resources\ApplicationResource;
use App\Modules\Recruiting\Http\Resources\CandidateResource;
use App\Modules\Recruiting\Http\Resources\TimelineEntryResource;
use App\Modules\Recruiting\Http\Resources\TouchpointResource;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Services\CandidateService;
use App\Modules\Recruiting\Services\TouchpointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class CandidateController
{
    use Actor;

    public function __construct(
        private readonly CandidateService $service,
        private readonly TouchpointService $touchpoints,
    ) {}

    public function index(ListCandidatesRequest $request): AnonymousResourceCollection
    {
        return CandidateResource::collection($this->service->list($this->actor($request), $request->filter()));
    }

    /** 409 {code: duplicate_candidate, existing_id, matched_by} when the contacts match someone (unless force_new). */
    public function store(SaveCandidateRequest $request): JsonResponse
    {
        $candidate = $this->service->create($this->actor($request), $request->candidateData(), $request->forceNew());

        return (new CandidateResource($this->service->find($candidate->id)))->response()->setStatusCode(201);
    }

    /** The card: contacts, source/UTM and every application with its route. */
    public function show(Request $request, Candidate $candidate): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('view', $candidate);
        $data = (new CandidateResource($this->service->find($candidate->id)))->toArray($request);
        $data['applications'] = ApplicationResource::collection($this->service->applications($candidate))->toArray($request);

        return new JsonResponse(['data' => $data]);
    }

    public function update(SaveCandidateRequest $request, Candidate $candidate): CandidateResource
    {
        $this->service->update($this->actor($request), $candidate, $request->candidateData());

        return new CandidateResource($this->service->find($candidate->id));
    }

    public function timeline(TimelineRequest $request, Candidate $candidate): AnonymousResourceCollection
    {
        return TimelineEntryResource::collection(
            $this->touchpoints->timeline($candidate, $request->channels(), $request->withStages(), $request->perPage()),
        );
    }

    public function logTouch(LogTouchpointRequest $request, Candidate $candidate): JsonResponse
    {
        $touchpoint = $this->touchpoints->log($this->actor($request), $candidate, $request->touchpointData());

        return (new TouchpointResource($touchpoint))->response()->setStatusCode(201);
    }
}
