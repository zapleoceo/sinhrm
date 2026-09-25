<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Controllers;

use App\Models\User;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Scripts\Http\Requests\ListTasksRequest;
use App\Modules\Scripts\Http\Requests\UpdateTaskRequest;
use App\Modules\Scripts\Http\Resources\TaskResource;
use App\Modules\Scripts\Models\Task;
use App\Modules\Scripts\Services\TaskService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class TaskController
{
    public function __construct(private readonly TaskService $tasks, private readonly CandidateRepository $candidates) {}

    /** Up to 200 tasks, open first and soonest due first. candidate_id requires access to that candidate. */
    public function index(ListTasksRequest $request): AnonymousResourceCollection
    {
        $actor = $request->user();
        assert($actor instanceof User);
        $filter = $request->filter();
        if ($filter->candidateId !== null) {
            $candidate = $this->candidates->find($filter->candidateId);
            abort_if($candidate === null, 404);
            Gate::forUser($actor)->authorize('view', $candidate);
        }

        return TaskResource::collection($this->tasks->list($actor, $filter));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        return new TaskResource($this->tasks->setDone($task, $request->done())->load(['candidate', 'application.vacancy']));
    }
}
