<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Controllers;

use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Http\Requests\CreateOneOnOneRequest;
use App\Modules\Perform\Http\Requests\ListPerformRequest;
use App\Modules\Perform\Http\Requests\SaveOneOnOneTemplateRequest;
use App\Modules\Perform\Http\Requests\UpdateOneOnOneRequest;
use App\Modules\Perform\Http\Resources\OneOnOneResource;
use App\Modules\Perform\Models\OneOnOne;
use App\Modules\Perform\Services\OneOnOneService;
use App\Modules\Perform\Services\PerformAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** 1:1 meetings and their agenda templates (template writes: admins, route gate). Rules — OneOnOneService. */
final class OneOnOneController extends PerformController
{
    public function __construct(PerformAccess $access, private readonly OneOnOneService $meetings)
    {
        parent::__construct($access);
    }

    public function index(ListPerformRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);

        return new JsonResponse(['data' => $this->meetings->list($viewer, $request->employeeId(), $request->status())
            ->map(fn (OneOnOne $m): array => $this->resource($viewer, $m)->resolve())->values()->all()]);
    }

    public function show(Request $request, int $id): OneOnOneResource
    {
        $viewer = $this->viewer($request);

        return $this->resource($viewer, $this->meetings->findVisible($viewer, $id));
    }

    public function store(CreateOneOnOneRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $meeting = $this->meetings->create($this->actor($request), $viewer, $request->payload());

        return $this->resource($viewer, $meeting)->response()->setStatusCode(201);
    }

    public function update(UpdateOneOnOneRequest $request, int $id): OneOnOneResource
    {
        $viewer = $this->viewer($request);
        $meeting = $this->meetings->update($viewer, $this->meetings->findVisible($viewer, $id), $request->payload());

        return $this->resource($viewer, $meeting);
    }

    public function destroy(Request $request, int $id): Response
    {
        $viewer = $this->viewer($request);
        $this->meetings->delete($viewer, $this->meetings->findVisible($viewer, $id));

        return response()->noContent();
    }

    public function templates(): JsonResponse
    {
        return new JsonResponse(['data' => $this->meetings->templates()->values()]);
    }

    public function storeTemplate(SaveOneOnOneTemplateRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->meetings->saveTemplate($this->actor($request), null, $request->payload())], 201);
    }

    public function updateTemplate(SaveOneOnOneTemplateRequest $request, int $id): JsonResponse
    {
        $template = $this->meetings->findTemplate($id);

        return new JsonResponse(['data' => $this->meetings->saveTemplate($this->actor($request), $template, $request->payload())]);
    }

    public function destroyTemplate(int $id): Response
    {
        $this->meetings->deleteTemplate($this->meetings->findTemplate($id));

        return response()->noContent();
    }

    private function resource(PerformViewer $viewer, OneOnOne $meeting): OneOnOneResource
    {
        return OneOnOneResource::for($meeting, $this->meetings->isMeetingManager($viewer, $meeting), $this->meetings->canManage($viewer, $meeting));
    }
}
