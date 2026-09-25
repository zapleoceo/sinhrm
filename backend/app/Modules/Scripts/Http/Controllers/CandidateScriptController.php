<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Controllers;

use App\Models\User;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Scripts\Http\Resources\EvaluationResource;
use App\Modules\Scripts\Services\EvaluationService;
use App\Modules\Scripts\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Scripts inside the candidate card: filled message templates and the evaluation of a touch. */
final class CandidateScriptController
{
    public function __construct(
        private readonly TemplateService $templates,
        private readonly EvaluationService $evaluations,
        private readonly RecruitingScope $scope,
    ) {}

    /** GET /api/candidates/{candidate}/templates — same visibility as the card. */
    public function templates(Request $request, Candidate $candidate): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('view', $candidate);

        return new JsonResponse(['data' => $this->templates->forCandidate($actor, $candidate)]);
    }

    /** GET /api/touchpoints/{touchpoint}/evaluation — visible with the candidate (or the inbox item); missing but eligible → evaluated now; otherwise 404 not_evaluated. */
    public function evaluation(Request $request, Touchpoint $touchpoint): JsonResponse
    {
        $actor = $this->actor($request);
        $candidate = $touchpoint->candidate;
        $visible = $candidate !== null
            ? $this->scope->canSeeCandidate($actor, $candidate)
            : $this->scope->canSeeInboxItem($actor, $touchpoint);
        abort_unless($visible, 403);

        // Always 200, also when the evaluation was just computed by the lazy fallback.
        return (new EvaluationResource($this->evaluations->forTouchpoint($touchpoint)))->response()->setStatusCode(200);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
