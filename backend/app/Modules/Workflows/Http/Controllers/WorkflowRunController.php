<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Http\Controllers;

use App\Models\User;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Services\EmployeeService;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Workflows\Http\Requests\ListRunsRequest;
use App\Modules\Workflows\Http\Requests\SkipStepRequest;
use App\Modules\Workflows\Http\Requests\StartRunRequest;
use App\Modules\Workflows\Http\Resources\WorkflowRunResource;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use App\Modules\Workflows\Services\WorkflowRunService;
use App\Modules\Workflows\Services\WorkflowTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Runs. Reading: admin (all) and managers (employees below them); start / cancel / retry: admin;
 * complete / skip a step: its assignee or an admin (answer: the step's new state only — an assignee may not see the
 * run itself). Not visible → 404.
 */
final class WorkflowRunController
{
    public function __construct(
        private readonly WorkflowRunService $runs,
        private readonly WorkflowTemplateService $templates,
        private readonly EmployeeService $employees,
        private readonly PeopleScope $scope,
    ) {}

    public function index(ListRunsRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $ctx = $this->scope->for($actor);

        return new JsonResponse(['data' => $this->runs->list($ctx, $request->filter())->map(
            static fn (WorkflowRun $run): array => WorkflowRunResource::for($run, $ctx, $actor)->resolve(),
        )->values()->all()]);
    }

    public function show(Request $request, WorkflowRun $workflowRun): WorkflowRunResource
    {
        $actor = $this->actor($request);
        $ctx = $this->scope->for($actor);

        return WorkflowRunResource::for($this->runs->findVisible($ctx, $workflowRun->id), $ctx, $actor);
    }

    public function store(StartRunRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $run = $this->runs->start(
            $actor,
            $this->templates->find($request->templateId()),
            $this->employees->find($request->employeeId()),
            $request->anchorDate(),
        );

        return WorkflowRunResource::for($run, $this->scope->for($actor), $actor)->response()->setStatusCode(201);
    }

    public function cancel(Request $request, WorkflowRun $workflowRun): WorkflowRunResource
    {
        $actor = $this->actor($request);

        return WorkflowRunResource::for($this->runs->cancel($actor, $workflowRun), $this->scope->for($actor), $actor);
    }

    public function complete(Request $request, WorkflowRun $workflowRun, WorkflowRunStep $runStep): JsonResponse
    {
        [$actor] = $this->authorizeStep($request, $workflowRun, $runStep);

        return $this->stepState($this->runs->complete($actor, $runStep));
    }

    public function skip(SkipStepRequest $request, WorkflowRun $workflowRun, WorkflowRunStep $runStep): JsonResponse
    {
        [$actor] = $this->authorizeStep($request, $workflowRun, $runStep);

        return $this->stepState($this->runs->skip($actor, $runStep, $request->reason()));
    }

    /** Admin only (route gate). Runs the failed step again right away. */
    public function retry(Request $request, WorkflowRun $workflowRun, WorkflowRunStep $runStep): JsonResponse
    {
        abort_if($runStep->run_id !== $workflowRun->id, 404);

        return $this->stepState($this->runs->retry($this->actor($request), $runStep));
    }

    /** @return array{0: User, 1: PeopleContext} */
    private function authorizeStep(Request $request, WorkflowRun $run, WorkflowRunStep $step): array
    {
        abort_if($step->run_id !== $run->id, 404);
        $actor = $this->actor($request);
        $ctx = $this->scope->for($actor);
        abort_unless($this->runs->canActOn($ctx, $actor, $step), 403);

        return [$actor, $ctx];
    }

    private function stepState(WorkflowRunStep $step): JsonResponse
    {
        return new JsonResponse(['data' => [
            'id' => $step->id,
            'run_id' => $step->run_id,
            'status' => $step->status->value,
            'completed_at' => $step->completed_at?->toIso8601String(),
            'result' => $step->result === null ? null : (object) $step->result,
            'run_status' => $step->run()->value('status'),
        ]]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
