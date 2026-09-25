<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\ApplyCandidateRequest;
use App\Modules\Recruiting\Http\Requests\ListVacanciesRequest;
use App\Modules\Recruiting\Http\Requests\SaveVacancyRequest;
use App\Modules\Recruiting\Http\Resources\ApplicationResource;
use App\Modules\Recruiting\Http\Resources\VacancyResource;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\ApplicationService;
use App\Modules\Recruiting\Services\VacancyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class VacancyController
{
    use Actor;

    public function __construct(private readonly VacancyService $service) {}

    public function index(ListVacanciesRequest $request): AnonymousResourceCollection
    {
        return VacancyResource::collection($this->service->list($this->actor($request), $request->filter()));
    }

    public function store(SaveVacancyRequest $request): JsonResponse
    {
        $vacancy = $this->service->create($this->actor($request), $request->vacancyData());

        return (new VacancyResource($vacancy))->response()->setStatusCode(201);
    }

    public function show(Request $request, Vacancy $vacancy): VacancyResource
    {
        Gate::forUser($this->actor($request))->authorize('view', $vacancy);

        return new VacancyResource($this->service->find($vacancy->id));
    }

    public function update(SaveVacancyRequest $request, Vacancy $vacancy): VacancyResource
    {
        return new VacancyResource($this->service->update($this->actor($request), $vacancy, $request->vacancyData()));
    }

    /** Kanban: the vacancy (with its pipeline stages) and every application; the client groups by stage_id. */
    public function board(Request $request, Vacancy $vacancy): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('view', $vacancy);
        $vacancy = $this->service->find($vacancy->id);

        return new JsonResponse(['data' => [
            'vacancy' => (new VacancyResource($vacancy))->toArray($request),
            'applications' => ApplicationResource::collection($this->service->boardApplications($vacancy))->toArray($request),
        ]]);
    }

    public function apply(ApplyCandidateRequest $request, Vacancy $vacancy, ApplicationService $applications): JsonResponse
    {
        $candidate = $request->candidate();
        assert($candidate instanceof Candidate);
        $application = $applications->apply($this->actor($request), $candidate, $vacancy);

        return (new ApplicationResource($applications->find($application->id)))->response()->setStatusCode(201);
    }
}
