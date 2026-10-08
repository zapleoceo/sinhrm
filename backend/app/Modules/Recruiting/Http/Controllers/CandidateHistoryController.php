<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Audit\Contracts\AuditHistory;
use App\Modules\Audit\Http\Requests\HistoryRequest;
use App\Modules\Audit\Http\Resources\AuditEntryResource;
use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Services\CandidateService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Candidate card "History" tab: audit rows of the candidate and of its applications (stage moves, rejections). */
final class CandidateHistoryController
{
    use ResolvesActor;

    public function __construct(private readonly AuditHistory $audit, private readonly CandidateService $candidates) {}

    public function __invoke(HistoryRequest $request, Candidate $candidate): AnonymousResourceCollection
    {
        Gate::forUser($this->actor($request))->authorize('view', $candidate);

        /** @var list<int> $applicationIds */
        $applicationIds = $this->candidates->applications($this->actor($request), $candidate)->modelKeys();

        return AuditEntryResource::collection($this->audit->history(
            ['candidate' => [$candidate->id], 'application' => $applicationIds],
            $request->page(),
            $request->perPage(),
        ));
    }
}
