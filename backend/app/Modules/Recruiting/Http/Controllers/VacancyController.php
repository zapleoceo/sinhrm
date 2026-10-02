<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Http\Requests\ApplyCandidateRequest;
use App\Modules\Recruiting\Http\Requests\ListVacanciesRequest;
use App\Modules\Recruiting\Http\Requests\SaveVacancyRequest;
use App\Modules\Recruiting\Http\Requests\VacancyTextRequest;
use App\Modules\Recruiting\Http\Resources\ApplicationResource;
use App\Modules\Recruiting\Http\Resources\VacancyResource;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\ApplicationService;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Recruiting\Services\ReportService;
use App\Modules\Recruiting\Services\VacancyService;
use App\Modules\Recruiting\Services\VacancyTextService;
use App\Modules\Recruiting\Support\VacancyOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class VacancyController
{
    use ResolvesActor;

    public function __construct(private readonly VacancyService $service) {}

    public function index(ListVacanciesRequest $request): AnonymousResourceCollection
    {
        $actor = $this->actor($request);

        return VacancyResource::collection($this->service->list($actor, $request->filter()))
            ->additional(['meta' => ['active_count' => $this->service->activeCount($actor)]]);
    }

    /** Generic option lists of the vacancy form (codes; labels are translated in the UI). */
    public function options(): JsonResponse
    {
        return new JsonResponse(['data' => VacancyOptions::all()]);
    }

    /** «Створити з ШІ»: an AI draft of one section (done now, or deferred → poll aiTextResult). */
    public function aiText(VacancyTextRequest $request, VacancyTextService $texts, RecruitingScope $scope): JsonResponse
    {
        $actor = $this->actor($request);
        $facts = $request->facts();
        // Same branch scope as creating a vacancy: no drafts «for» a branch the user cannot see.
        if ($facts['branch_id'] !== null && ! $scope->for($actor)->allowsBranch($facts['branch_id'])) {
            throw RecruitingException::vacancyOutOfScope();
        }

        return new JsonResponse(['data' => $texts->generate($actor, $facts)]);
    }

    public function aiTextResult(Request $request, int $aiRequest, VacancyTextService $texts): JsonResponse
    {
        return new JsonResponse(['data' => $texts->poll($this->actor($request), $aiRequest)]);
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

    /** Vacancy card block "where applicants came from" (tz3): channel × how added, count and share. */
    public function sources(Request $request, Vacancy $vacancy, ReportService $reports): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('view', $vacancy);

        return new JsonResponse(['data' => $reports->vacancySources($vacancy->id)]);
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
