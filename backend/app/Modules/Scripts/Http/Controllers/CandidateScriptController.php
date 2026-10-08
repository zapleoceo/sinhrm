<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\Recruiting\Contracts\RecruitingAccess;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Support\ApplicationVisibility;
use App\Modules\Recruiting\Support\TouchpointRedaction;
use App\Modules\Scripts\Http\Resources\EvaluationResource;
use App\Modules\Scripts\Services\EvaluationService;
use App\Modules\Scripts\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Scripts inside the candidate card: filled message templates and the evaluation of a touch. */
final class CandidateScriptController
{
    use ResolvesActor;

    public function __construct(
        private readonly TemplateService $templates,
        private readonly EvaluationService $evaluations,
        private readonly RecruitingAccess $scope,
    ) {}

    /** GET /api/candidates/{candidate}/templates — same visibility as the card. */
    public function templates(Request $request, Candidate $candidate): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('view', $candidate);

        return new JsonResponse(['data' => $this->templates->forCandidate($actor, $candidate)]);
    }

    /**
     * GET /api/touchpoints/{touchpoint}/evaluation — visible with the candidate (or the inbox item) and, when the
     * touch belongs to an application, with that application too (a candidate may apply in several branches, and the
     * timeline hides the touches of applications out of scope). Missing but eligible → evaluated now; otherwise
     * 404 not_evaluated. A restricted touch (offer) keeps its evaluation closed as well.
     */
    public function evaluation(Request $request, Touchpoint $touchpoint): JsonResponse
    {
        $actor = $this->actor($request);
        $candidate = $touchpoint->candidate;
        $visible = $candidate !== null
            ? $this->scope->canSeeCandidate($actor, $candidate) && $this->applicationVisible($actor, $touchpoint)
            : $this->scope->canSeeInboxItem($actor, $touchpoint);
        abort_unless($visible && ! TouchpointRedaction::restricted($touchpoint, $actor), 403);

        // Always 200, also when the evaluation was just computed by the lazy fallback.
        return (new EvaluationResource($this->evaluations->forTouchpoint($touchpoint)))->response()->setStatusCode(200);
    }

    /** The touch's application must be in the actor's scope — the same rule the candidate timeline applies. */
    private function applicationVisible(User $actor, Touchpoint $touchpoint): bool
    {
        return $touchpoint->application_id === null
            || ApplicationVisibility::query($this->scope->for($actor))->whereKey($touchpoint->application_id)->exists();
    }
}
