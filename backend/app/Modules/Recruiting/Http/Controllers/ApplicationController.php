<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\MoveApplicationRequest;
use App\Modules\Recruiting\Http\Requests\StaleRequest;
use App\Modules\Recruiting\Http\Resources\ApplicationResource;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Services\ApplicationService;
use App\Modules\Recruiting\Services\StalenessService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ApplicationController
{
    use Actor;

    public function move(MoveApplicationRequest $request, Application $application, ApplicationService $service): ApplicationResource
    {
        return new ApplicationResource($service->move($this->actor($request), $application, $request->moveData()));
    }

    /** Active applications without a real contact for ?days (default 3), oldest first, at most 200. */
    public function stale(StaleRequest $request, StalenessService $service): AnonymousResourceCollection
    {
        return ApplicationResource::collection($service->stale($this->actor($request), $request->days()))
            ->additional(['meta' => ['days' => $request->days()]]);
    }
}
