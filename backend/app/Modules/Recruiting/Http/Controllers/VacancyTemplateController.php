<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\SaveVacancyTemplateRequest;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Models\VacancyTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Vacancy form templates: shared by everyone who may create vacancies (recruiting-write). */
final class VacancyTemplateController
{
    use Actor;

    public function index(Request $request): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('create', Vacancy::class);
        $rows = VacancyTemplate::query()->orderBy('name')->orderBy('id')->limit(200)->get();

        return new JsonResponse(['data' => $rows->map(fn (VacancyTemplate $t): array => $this->present($t))->values()]);
    }

    public function store(SaveVacancyTemplateRequest $request): JsonResponse
    {
        $template = VacancyTemplate::query()->create([
            'name' => $request->string('name')->trim()->toString(),
            'data' => $request->templateData(),
            'created_by' => $this->actor($request)->id,
        ]);

        return new JsonResponse(['data' => $this->present($template)], 201);
    }

    public function destroy(Request $request, VacancyTemplate $template): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('create', Vacancy::class);
        $template->delete();

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(VacancyTemplate $template): array
    {
        return ['id' => $template->id, 'name' => $template->name, 'data' => $template->data, 'created_at' => $template->created_at?->toIso8601String()];
    }
}
