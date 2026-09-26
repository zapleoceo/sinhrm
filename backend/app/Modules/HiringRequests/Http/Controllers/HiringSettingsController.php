<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Http\Controllers;

use App\Modules\HiringRequests\Http\Requests\SaveHiringSettingsRequest;
use App\Modules\HiringRequests\Models\HiringRouteStep;
use App\Modules\HiringRequests\Services\HiringSettingsService;
use Illuminate\Http\JsonResponse;

/** Admin settings of hiring requests: approval route, configurable form fields, creators, auto-vacancy. */
final class HiringSettingsController
{
    public function __construct(private readonly HiringSettingsService $settings) {}

    public function show(): JsonResponse
    {
        return $this->respond();
    }

    public function update(SaveHiringSettingsRequest $request): JsonResponse
    {
        // The route is validated (and may throw 422) before anything is written: a rejected request changes nothing.
        $route = $request->routeSteps();
        if ($route !== null) {
            $this->settings->saveRoute($route);
        }
        $this->settings->save($request->settingsData());

        return $this->respond();
    }

    private function respond(): JsonResponse
    {
        $s = $this->settings->settings();

        return new JsonResponse(['data' => [
            'form_fields' => $s->form_fields ?? [],
            'creator_user_ids' => $s->creator_user_ids ?? [],
            'auto_vacancy' => $s->auto_vacancy,
            // Pickers of the settings page and of the final approval (recruiter of the vacancy).
            'users' => $this->settings->users(),
            'route' => $this->settings->route()->map(static fn (HiringRouteStep $step): array => [
                'id' => $step->id,
                'position' => $step->position,
                'name' => $step->name,
                'kind' => $step->kind->value,
                'role' => $step->role,
                'user' => $step->user === null ? null : ['id' => $step->user->id, 'name' => $step->user->name],
                'user_id' => $step->user_id,
                'sla_days' => $step->sla_days,
            ])->values()->all(),
        ]]);
    }
}
