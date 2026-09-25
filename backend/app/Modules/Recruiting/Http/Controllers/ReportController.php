<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\ReportRequest;
use App\Modules\Recruiting\Services\ReportService;
use Illuminate\Http\JsonResponse;

/** Manager reports ({data: {range, rows, totals}}), limited to the user's scope. */
final class ReportController
{
    use Actor;

    public function __construct(private readonly ReportService $reports) {}

    public function touches(ReportRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->reports->touches($this->actor($request), $request->range())]);
    }

    public function funnel(ReportRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->reports->funnel($this->actor($request), $request->range(), $request->vacancyId())]);
    }

    public function sources(ReportRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->reports->sources($this->actor($request), $request->range())]);
    }

    public function rejectReasons(ReportRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->reports->rejectReasons($this->actor($request), $request->range())]);
    }
}
