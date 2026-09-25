<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\CreatePipelineRequest;
use App\Modules\Recruiting\Http\Requests\SaveRejectReasonRequest;
use App\Modules\Recruiting\Http\Resources\PipelineResource;
use App\Modules\Recruiting\Http\Resources\RejectReasonResource;
use App\Modules\Recruiting\Models\RejectReason;
use App\Modules\Recruiting\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Pipelines and reject reasons. Reading: any active user; writing: gate recruiting-manage (routes.php). */
final class PipelineController
{
    use Actor;

    public function __construct(private readonly PipelineService $service) {}

    public function index(): AnonymousResourceCollection
    {
        return PipelineResource::collection($this->service->all());
    }

    public function store(CreatePipelineRequest $request): JsonResponse
    {
        $pipeline = $this->service->create($this->actor($request), $request->string('name')->trim()->toString(), $request->stages());

        return (new PipelineResource($pipeline))->response()->setStatusCode(201);
    }

    /** ?all=1 → inactive too (admin screens); default → active only (reject prompt). */
    public function rejectReasons(Request $request): AnonymousResourceCollection
    {
        return RejectReasonResource::collection($this->service->rejectReasons(! $request->boolean('all')));
    }

    public function storeRejectReason(SaveRejectReasonRequest $request): JsonResponse
    {
        $reason = $this->service->saveRejectReason($this->actor($request), null, $request->reasonAttributes());

        return (new RejectReasonResource($reason))->response()->setStatusCode(201);
    }

    public function updateRejectReason(SaveRejectReasonRequest $request, RejectReason $rejectReason): RejectReasonResource
    {
        return new RejectReasonResource($this->service->saveRejectReason($this->actor($request), $rejectReason, $request->reasonAttributes()));
    }
}
