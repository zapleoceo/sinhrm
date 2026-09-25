<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Controllers;

use App\Models\User;
use App\Modules\Scripts\Http\Requests\SaveDraftRequest;
use App\Modules\Scripts\Http\Requests\SaveScriptRequest;
use App\Modules\Scripts\Http\Requests\TestScriptRequest;
use App\Modules\Scripts\Http\Requests\UpdateScriptRequest;
use App\Modules\Scripts\Http\Resources\ScriptResource;
use App\Modules\Scripts\Http\Resources\ScriptVersionResource;
use App\Modules\Scripts\Models\Script;
use App\Modules\Scripts\Services\EvaluationService;
use App\Modules\Scripts\Services\ScriptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Scripts: reading for every active user, changes for superadmin/admin (route gate scripts-manage). */
final class ScriptController
{
    public function __construct(private readonly ScriptService $scripts, private readonly EvaluationService $evaluations) {}

    /** ?archived=1 — include archived scripts. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ScriptResource::collection($this->scripts->list($request->boolean('archived')));
    }

    public function store(SaveScriptRequest $request): JsonResponse
    {
        $script = $this->scripts->create($this->actor($request), $request->name(), $request->channel(), $request->scriptContent());

        return $this->full($script)->response()->setStatusCode(201);
    }

    /** The editor: the active version and the draft with their content. */
    public function show(Script $script): ScriptResource
    {
        return $this->full($script);
    }

    public function update(UpdateScriptRequest $request, Script $script): ScriptResource
    {
        $this->scripts->update($script, $request->name(), $request->archived());

        return $this->full($script);
    }

    /** Always 200 (also when the draft row was just created: PUT replaces "the draft" of the script). */
    public function saveDraft(SaveDraftRequest $request, Script $script): JsonResponse
    {
        $draft = $this->scripts->saveDraft($this->actor($request), $script, $request->scriptContent())->load('author');

        return (new ScriptVersionResource($draft))->response()->setStatusCode(200);
    }

    public function publish(Request $request, Script $script): ScriptResource
    {
        $this->scripts->publish($this->actor($request), $script);

        return $this->full($script);
    }

    public function activate(Script $script, int $version): ScriptResource
    {
        $this->scripts->activate($script, $version);

        return $this->full($script);
    }

    public function versions(Script $script): AnonymousResourceCollection
    {
        return ScriptVersionResource::collection($this->scripts->versions($script))
            ->additional(['meta' => ['active_version_id' => $script->active_version_id]]);
    }

    /** Evaluation preview of a pasted text; nothing is stored. */
    public function test(TestScriptRequest $request, Script $script): JsonResponse
    {
        $content = $this->scripts->contentForTest($script, $request->preferActive());

        return new JsonResponse(['data' => $this->evaluations->evaluate($content, $request->text())->toArray()]);
    }

    private function full(Script $script): ScriptResource
    {
        return (new ScriptResource($this->scripts->find($script->id)))->withContent();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
