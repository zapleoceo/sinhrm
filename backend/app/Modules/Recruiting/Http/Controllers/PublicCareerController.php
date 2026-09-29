<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Documents\Support\MarkdownRenderer;
use App\Modules\Recruiting\Http\Requests\PublicApplyRequest;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\CareerSiteService;
use Illuminate\Http\JsonResponse;

/** Anonymous career page API: only public fields of published open vacancies; no people, no internal ids. */
final readonly class PublicCareerController
{
    public function __construct(private CareerSiteService $service) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->service->published()->map(fn (Vacancy $v): array => $this->present($v, false))->values()]);
    }

    public function show(string $slug): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($this->service->findPublished($slug), true)]);
    }

    public function apply(PublicApplyRequest $request, string $slug): JsonResponse
    {
        $vacancy = $this->service->findPublished($slug);
        // Honeypot filled: answer like a success so bots learn nothing, store nothing.
        if (! $request->isSpam()) {
            $cv = $request->file('cv');
            $this->service->apply(
                $vacancy,
                $request->string('name')->trim()->toString(),
                $request->string('email')->trim()->toString(),
                $request->filled('phone') ? $request->string('phone')->trim()->toString() : null,
                $request->filled('message') ? $request->string('message')->trim()->toString() : null,
                $cv === null ? null : (string) $cv->get(),
                $cv?->getClientOriginalName(),
                (string) $request->ip(),
            );
        }

        return new JsonResponse(['ok' => true], 201);
    }

    /** @return array<string, mixed> */
    private function present(Vacancy $vacancy, bool $full): array
    {
        $data = [
            'slug' => $vacancy->slug,
            'title' => $vacancy->title,
            'branch' => $vacancy->branch->name,
            'position' => $vacancy->position?->name,
            'opened_at' => $vacancy->opened_at?->toDateString(),
            'city' => $vacancy->city?->name,
            'employment_type' => $vacancy->employment_type,
            'work_format' => $vacancy->work_format,
            // Salary is internal unless the recruiter ticked «Показувати кандидатам».
            'salary' => $vacancy->salary_visible && ($vacancy->salary_min !== null || $vacancy->salary_max !== null) ? [
                'min' => $vacancy->salary_min === null ? null : (float) $vacancy->salary_min,
                'max' => $vacancy->salary_max === null ? null : (float) $vacancy->salary_max,
                'currency' => $vacancy->salary_currency,
            ] : null,
        ];
        if ($full) {
            $data['description'] = $vacancy->public_description;
            // Markdown sections, rendered on the server like Knowledge articles: raw HTML escaped, unsafe links dropped.
            foreach (['requirements', 'responsibilities', 'additional_info'] as $section) {
                $text = $vacancy->{$section};
                $data[$section.'_html'] = is_string($text) && trim($text) !== '' ? MarkdownRenderer::toHtml($text) : null;
            }
        }

        return $data;
    }
}
