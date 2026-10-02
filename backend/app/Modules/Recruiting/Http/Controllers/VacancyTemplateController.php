<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\Recruiting\Http\Requests\SaveVacancyTemplateRequest;
use App\Modules\Recruiting\Models\VacancyTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Vacancy form templates: listed and used by everyone who may create vacancies (recruiting-write); renamed or deleted
 * only by the author or an admin (VacancyTemplatePolicy). Each row carries `can_manage` for the UI.
 */
final class VacancyTemplateController
{
    use ResolvesActor;

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', VacancyTemplate::class);
        $rows = VacancyTemplate::query()->orderBy('name')->orderBy('id')->limit(200)->get();

        return new JsonResponse(['data' => $rows->map(fn (VacancyTemplate $t): array => $this->present($t, $actor))->values()]);
    }

    public function store(SaveVacancyTemplateRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $template = VacancyTemplate::query()->create([
            'name' => $request->string('name')->trim()->toString(),
            'data' => $request->templateData(),
            'created_by' => $actor->id,
        ]);

        return new JsonResponse(['data' => $this->present($template, $actor)], 201);
    }

    public function update(SaveVacancyTemplateRequest $request, VacancyTemplate $template): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('update', $template);
        if ($request->has('name')) {
            $template->name = $request->string('name')->trim()->toString();
        }
        if ($request->has('data')) {
            $template->data = $request->templateData();
        }
        $template->save();

        return new JsonResponse(['data' => $this->present($template, $actor)]);
    }

    public function destroy(Request $request, VacancyTemplate $template): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('delete', $template);
        $template->delete();

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(VacancyTemplate $template, User $actor): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'data' => $template->data,
            'created_by' => $template->created_by,
            'can_manage' => Gate::forUser($actor)->allows('delete', $template),
            'created_at' => $template->created_at?->toIso8601String(),
        ];
    }
}
