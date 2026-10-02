<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\Recruiting\Http\Requests\BulkCandidatesRequest;
use App\Modules\Recruiting\Services\CandidateBulkService;
use Illuminate\Http\JsonResponse;

/** POST /candidates/bulk → {data: [{id, ok, error}]} (200 even when some items failed). */
final class CandidateBulkController
{
    use ResolvesActor;

    public function __invoke(BulkCandidatesRequest $request, CandidateBulkService $service): JsonResponse
    {
        /** @var array<string, mixed> $input */
        $input = $request->validated();

        return new JsonResponse(['data' => $service->run($this->actor($request), (string) $input['action'], $request->ids(), $input)]);
    }
}
