<?php

declare(strict_types=1);

namespace App\Modules\Overview\Http\Controllers;

use App\Models\User;
use App\Modules\Overview\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/dashboard — the home page data ({data: {counts, my_tasks, stale, funnel, funnel_insights, day_route, touches}}). */
final class DashboardController
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * Home page data.
     *
     * One request for the whole home page, every figure limited to the user's Recruiting scope:
     * `counts`, `stale_days`, `my_tasks`, `stale`, `warnings`, `funnel`, `touches {days, by_channel}`;
     * `funnel_insights {period_days, min_sample, min_offer_observations, bottleneck: {from, to, from_kind, to_kind,
     * conversion, passed, decided}|null, offer_path: {days, observations}|null}` — null when there is too little data;
     * `day_route {date, timezone, interviews, tasks, items: [{kind: interview|task, id, at, end, title, meeting_type?, type?,
     * candidate: {id, name}|null}]}` — the user's day (time zone config app.user_timezone): meetings the user scheduled or interviews, and the user's tasks due that day;
     * plus blocks of other modules (`timeoff`, `hiring`, `time`). Formulas: docs/modules/overview.md.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return new JsonResponse(['data' => $this->dashboard->build($actor)]);
    }
}
