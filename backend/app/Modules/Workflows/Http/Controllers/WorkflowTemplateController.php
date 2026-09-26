<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Http\Controllers;

use App\Models\User;
use App\Modules\Workflows\Http\Requests\ReorderStepsRequest;
use App\Modules\Workflows\Http\Requests\SaveWorkflowTemplateRequest;
use App\Modules\Workflows\Http\Requests\WebhookSecretRequest;
use App\Modules\Workflows\Http\Resources\WorkflowTemplateResource;
use App\Modules\Workflows\Models\WorkflowTemplate;
use App\Modules\Workflows\Services\WorkflowTemplateService;
use App\Modules\Workflows\Support\WebhookSecrets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Workflow templates (route gate workflows-manage: superadmin, admin). */
final class WorkflowTemplateController
{
    public function __construct(
        private readonly WorkflowTemplateService $templates,
        private readonly WebhookSecrets $secrets,
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->templates->list()->map(
            fn (WorkflowTemplate $t): array => $this->resource($t)->resolve(),
        )->values()->all()]);
    }

    public function show(WorkflowTemplate $workflowTemplate): WorkflowTemplateResource
    {
        return $this->resource($this->templates->find($workflowTemplate->id));
    }

    public function store(SaveWorkflowTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->create($this->actor($request), $request->templateAttributes(), $request->steps());

        return $this->resource($template)->response()->setStatusCode(201);
    }

    public function update(SaveWorkflowTemplateRequest $request, WorkflowTemplate $workflowTemplate): WorkflowTemplateResource
    {
        return $this->resource($this->templates->update(
            $this->actor($request),
            $workflowTemplate,
            $request->templateAttributes(),
            $request->steps(),
        ));
    }

    public function reorder(ReorderStepsRequest $request, WorkflowTemplate $workflowTemplate): WorkflowTemplateResource
    {
        return $this->resource($this->templates->reorder($this->templates->find($workflowTemplate->id), $request->ids()));
    }

    public function destroy(Request $request, WorkflowTemplate $workflowTemplate): Response
    {
        $this->templates->delete($this->actor($request), $workflowTemplate);

        return new Response(status: 204);
    }

    public function webhookSecret(WebhookSecretRequest $request, WorkflowTemplate $workflowTemplate): WorkflowTemplateResource
    {
        $this->templates->setWebhookSecret($this->actor($request), $workflowTemplate, $request->secret());

        return $this->resource($this->templates->find($workflowTemplate->id));
    }

    private function resource(WorkflowTemplate $template): WorkflowTemplateResource
    {
        return WorkflowTemplateResource::withSecret($template, $this->secrets->describe($template->id));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
