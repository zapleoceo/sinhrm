<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Audit\Http\Requests\HistoryRequest;
use App\Modules\Audit\Http\Resources\AuditEntryResource;
use App\Modules\Audit\Services\AuditService;
use App\Modules\Recruiting\Models\Candidate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Candidate card "History" tab: audit rows of the candidate and of its applications (stage moves, rejections). */
final class CandidateHistoryController
{
    use Actor;

    public function __construct(private readonly AuditService $audit) {}

    public function __invoke(HistoryRequest $request, Candidate $candidate): AnonymousResourceCollection
    {
        Gate::forUser($this->actor($request))->authorize('view', $candidate);

        /** @var list<int> $applicationIds */
        $applicationIds = $candidate->applications()->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        return AuditEntryResource::collection($this->audit->history(
            ['candidate' => [$candidate->id], 'application' => $applicationIds],
            $request->page(),
            $request->perPage(),
        ));
    }
}
