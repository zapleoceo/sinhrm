<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\ClipCandidateRequest;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\ClipperService;
use App\Modules\Recruiting\Services\ExtensionTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Browser extension "SinHRM Clipper": its token (session routes /api/me/extension-token) and the token-only API
 * /api/clipper/* (whoami + vacancies, import of one profile page).
 */
final class ExtensionController
{
    use Actor;

    public function __construct(
        private readonly ExtensionTokenService $tokens,
        private readonly ClipperService $clipper,
    ) {}

    public function tokenStatus(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->tokens->status($this->actor($request))->toArray()]);
    }

    /** The plaintext token is in this response only; it is never shown again. */
    public function issueToken(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->tokens->issue($this->actor($request))->toArray()], 201);
    }

    public function revokeToken(Request $request): Response
    {
        $this->tokens->revoke($this->actor($request));

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return new JsonResponse(['data' => [
            'user' => ['id' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
            'vacancies' => array_map(static fn (Vacancy $v): array => [
                'id' => $v->id,
                'title' => $v->title,
                'branch' => $v->relationLoaded('branch') ? $v->branch->name : null,
            ], $this->clipper->vacancies($actor)),
        ]]);
    }

    /** 201 new / 200 matched {candidate_id, url, created}; 409 duplicate_candidate {restricted}; 403 vacancy_out_of_scope. */
    public function clip(ClipCandidateRequest $request): JsonResponse
    {
        $result = $this->clipper->import($this->actor($request), $request->clipData());
        $id = $result->candidate->id;

        return new JsonResponse(['data' => [
            'candidate_id' => $id,
            'url' => rtrim((string) config('app.frontend_url'), '/').'/candidates/'.$id,
            'created' => $result->created,
        ]], $result->created ? 201 : 200);
    }
}
