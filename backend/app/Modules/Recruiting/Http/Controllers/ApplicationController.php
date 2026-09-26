<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\AssignableUsersRequest;
use App\Modules\Recruiting\Http\Requests\AssignInterviewersRequest;
use App\Modules\Recruiting\Http\Requests\MoveApplicationRequest;
use App\Modules\Recruiting\Http\Requests\StaleRequest;
use App\Modules\Recruiting\Http\Resources\ApplicationResource;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Services\ApplicationService;
use App\Modules\Recruiting\Services\HiringTeamService;
use App\Modules\Recruiting\Services\StalenessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ApplicationController
{
    use Actor;

    public function move(MoveApplicationRequest $request, Application $application, ApplicationService $service): ApplicationResource
    {
        return new ApplicationResource($service->move($this->actor($request), $application, $request->moveData()));
    }

    /** PUT /applications/{application}/interviewers {user_ids: int[]} — replaces the interviewers (contextual role). */
    public function interviewers(AssignInterviewersRequest $request, Application $application, HiringTeamService $service): ApplicationResource
    {
        return new ApplicationResource($service->assignInterviewers($this->actor($request), $application, $request->userIds()));
    }

    /** GET /recruiting/assignable-users?q= — people for the hiring-team pickers (id, name; at most 50). */
    public function assignableUsers(AssignableUsersRequest $request, HiringTeamService $service): JsonResponse
    {
        return new JsonResponse(['data' => $service->assignableUsers($this->actor($request), $request->term())]);
    }

    /** Active applications without a real contact for ?days (default 3), oldest first, at most 200. */
    public function stale(StaleRequest $request, StalenessService $service): AnonymousResourceCollection
    {
        return ApplicationResource::collection($service->stale($this->actor($request), $request->days()))
            ->additional(['meta' => ['days' => $request->days()]]);
    }
}
