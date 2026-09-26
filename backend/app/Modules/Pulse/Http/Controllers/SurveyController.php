<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Controllers;

use App\Models\User;
use App\Modules\Pulse\Http\Requests\CreateWaveRequest;
use App\Modules\Pulse\Http\Requests\SaveSurveyRequest;
use App\Modules\Pulse\Http\Requests\UpdateWaveRequest;
use App\Modules\Pulse\Http\Resources\SurveyResource;
use App\Modules\Pulse\Http\Resources\WaveResource;
use App\Modules\Pulse\Models\SurveyResponse;
use App\Modules\Pulse\Services\ResponseService;
use App\Modules\Pulse\Services\SurveyService;
use App\Modules\Pulse\Support\SurveyTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Admin side (route gate pulse-manage): templates, the builder, waves, closing, identified responses. */
final class SurveyController
{
    public function __construct(private readonly SurveyService $surveys, private readonly ResponseService $responses) {}

    public function templates(): JsonResponse
    {
        return new JsonResponse(['data' => SurveyTemplates::all()]);
    }

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => SurveyResource::collection($this->surveys->list())->resolve()]);
    }

    public function show(int $id): SurveyResource
    {
        return new SurveyResource($this->surveys->find($id));
    }

    public function store(SaveSurveyRequest $request): JsonResponse
    {
        return (new SurveyResource($this->surveys->save($this->actor($request), null, $request->payload())))->response()->setStatusCode(201);
    }

    public function update(SaveSurveyRequest $request, int $id): SurveyResource
    {
        return new SurveyResource($this->surveys->save($this->actor($request), $this->surveys->find($id), $request->payload()));
    }

    public function destroy(int $id): Response
    {
        $this->surveys->delete($this->surveys->find($id));

        return response()->noContent();
    }

    public function waves(int $id): JsonResponse
    {
        return new JsonResponse(['data' => WaveResource::collection($this->surveys->waves($this->surveys->find($id)))->resolve()]);
    }

    public function storeWave(CreateWaveRequest $request, int $id): JsonResponse
    {
        $wave = $this->surveys->createWave($this->actor($request), $this->surveys->find($id), $request->payload());

        return (new WaveResource($wave))->response()->setStatusCode(201);
    }

    public function showWave(int $waveId): WaveResource
    {
        return new WaveResource($this->surveys->findWave($waveId));
    }

    /** PUT /api/pulse/waves/{id} {min_group_size}: raise only, not for closed waves. */
    public function updateWave(UpdateWaveRequest $request, int $waveId): WaveResource
    {
        return new WaveResource($this->surveys->raiseMinGroup($this->surveys->findWave($waveId), $request->minGroup()));
    }

    public function closeWave(int $waveId): WaveResource
    {
        return new WaveResource($this->surveys->closeWave($this->surveys->findWave($waveId)));
    }

    /** Non-anonymous waves only (409 anonymous_wave otherwise). */
    public function responses(int $waveId): JsonResponse
    {
        return new JsonResponse(['data' => $this->responses->identified($this->surveys->findWave($waveId))
            ->map(static fn (SurveyResponse $r): array => [
                'id' => $r->id,
                'employee_id' => $r->employee_id,
                'answers' => (object) $r->answers,
                'submitted_on' => $r->submitted_on->toDateString(),
            ])->values()->all()]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
