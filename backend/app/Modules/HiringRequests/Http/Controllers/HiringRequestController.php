<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Http\Controllers;

use App\Models\User;
use App\Modules\HiringRequests\Enums\HiringRequestStatus;
use App\Modules\HiringRequests\Http\Requests\HiringDecisionRequest;
use App\Modules\HiringRequests\Http\Requests\SaveHiringRequestRequest;
use App\Modules\HiringRequests\Http\Resources\HiringRequestPresenter;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\HiringRequests\Services\HiringAccess;
use App\Modules\HiringRequests\Services\HiringRequestService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Hiring requests: list, approval inbox, create/edit/submit, decisions, cancel/close, the vacancy link. */
final class HiringRequestController
{
    public function __construct(private readonly HiringRequestService $service, private readonly HiringAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(HiringRequestStatus::class)],
            'mine' => ['nullable', 'boolean'],
        ]);
        $filter = ['status' => $request->filled('status') ? $request->string('status')->toString() : null, 'mine' => $request->boolean('mine')];

        return $this->collection($this->actor($request), $this->service->list($this->actor($request), $filter));
    }

    public function inbox(Request $request): JsonResponse
    {
        return $this->collection($this->actor($request), $this->service->inbox($this->actor($request)));
    }

    /** What the current user may do: create, and the configurable form fields for the wizard. */
    public function meta(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => [
            'can_create' => $this->access->canCreate($this->actor($request)),
            'can_manage' => $this->access->isAdmin($this->actor($request)),
            'form_fields' => $this->service->fields(),
        ]]);
    }

    public function store(SaveHiringRequestRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $created = $this->service->create($actor, $request->requestData(), $request->submitNow());

        return new JsonResponse(['data' => $this->detail($actor, $created)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);

        return new JsonResponse(['data' => $this->detail($actor, $this->service->findVisible($actor, $id))]);
    }

    public function update(SaveHiringRequestRequest $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);
        $updated = $this->service->update($actor, $this->service->findVisible($actor, $id), $request->requestData());

        return new JsonResponse(['data' => $this->detail($actor, $updated)]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);

        return new JsonResponse(['data' => $this->detail($actor, $this->service->submit($actor, $this->service->findVisible($actor, $id)))]);
    }

    public function decide(HiringDecisionRequest $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);
        $decided = $this->service->decide($actor, $this->service->findVisible($actor, $id), $request->approve(), $request->comment(), $request->recruiterId());

        return new JsonResponse(['data' => $this->detail($actor, $decided)]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);

        return new JsonResponse(['data' => $this->detail($actor, $this->service->cancel($actor, $this->service->findVisible($actor, $id)))]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);

        return new JsonResponse(['data' => $this->detail($actor, $this->service->close($actor, $this->service->findVisible($actor, $id)))]);
    }

    /** HR: open the vacancy now (auto-vacancy off, or retry). Idempotent. Body: {recruiter_id?}. */
    public function createVacancy(Request $request, int $id): JsonResponse
    {
        $request->validate(['recruiter_id' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->actor($request);
        $hiring = $this->service->findVisible($actor, $id);
        $recruiter = $request->filled('recruiter_id') ? $request->integer('recruiter_id') : ($hiring->recruiter_id ?? $actor->id);

        return new JsonResponse(['data' => $this->detail($actor, $this->service->createVacancy($hiring, $recruiter))]);
    }

    /** HR: link an existing vacancy (the vacancy form's "Заявка на вакансію" field). Body: {vacancy_id}. */
    public function linkVacancy(Request $request, int $id): JsonResponse
    {
        $request->validate(['vacancy_id' => ['required', 'integer', 'exists:vacancies,id']]);
        $actor = $this->actor($request);

        return new JsonResponse(['data' => $this->detail($actor, $this->service->linkVacancy($this->service->findVisible($actor, $id), $request->integer('vacancy_id')))]);
    }

    /** @return array<string, mixed> */
    private function detail(User $actor, HiringRequest $r): array
    {
        return HiringRequestPresenter::present($r, $this->service->progress([$r])[$r->id], $this->access->flags($actor, $r), Carbon::now());
    }

    /** @param  Collection<int, HiringRequest>  $list */
    private function collection(User $actor, Collection $list): JsonResponse
    {
        $progress = $this->service->progress($list);
        $now = Carbon::now();

        return new JsonResponse(['data' => $list->map(fn (HiringRequest $r): array => HiringRequestPresenter::present($r, $progress[$r->id], $this->access->flags($actor, $r), $now))->values()->all()]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
