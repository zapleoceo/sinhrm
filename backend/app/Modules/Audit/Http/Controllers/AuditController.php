<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Http\Requests\ListAuditRequest;
use App\Modules\Audit\Http\Resources\AuditEntryResource;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Superadmin "Administration → Audit log". */
final class AuditController
{
    public function __construct(private readonly AuditService $service) {}

    public function index(ListAuditRequest $request): AnonymousResourceCollection
    {
        return AuditEntryResource::collection($this->service->search($request->filter()));
    }

    /** Values for the filter selects. */
    public function options(): JsonResponse
    {
        return new JsonResponse($this->service->options());
    }
}
