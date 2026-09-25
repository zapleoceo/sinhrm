<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Controllers;

use App\Models\User;
use App\Modules\Recruiting\Http\Requests\ReportRequest;
use App\Modules\Scripts\Services\ScriptReportService;
use Illuminate\Http\JsonResponse;

/** GET /api/reports/scripts?from&to — average score per recruiter, % next step fixed, miss rate per step. */
final class ScriptReportController
{
    public function __construct(private readonly ScriptReportService $reports) {}

    public function __invoke(ReportRequest $request): JsonResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return new JsonResponse(['data' => $this->reports->report($actor, $request->range())]);
    }
}
