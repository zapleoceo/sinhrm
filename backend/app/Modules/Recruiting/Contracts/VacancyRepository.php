<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\DTO\VacancyFilter;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\CareerSubmission;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Models\VacancyTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface VacancyRepository
{
    /** @return LengthAwarePaginator<int, Vacancy> */
    public function paginate(Scope $scope, VacancyFilter $filter): LengthAwarePaginator;

    /** With branch, recruiter, pipeline stages and application counts. */
    /** Active (open AND published) vacancies visible in the scope. */
    public function activeCount(Scope $scope): int;

    public function find(int $id): ?Vacancy;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Vacancy;

    /** @param  array<string, mixed>  $attributes */
    public function update(Vacancy $vacancy, array $attributes): Vacancy;

    /** The single open vacancy whose title equals $title case-insensitively; none or several → null. */
    public function findOpenByTitle(string $title): ?Vacancy;

    /** @return Collection<int, Application> every application of the vacancy with its candidate */
    public function boardApplications(Vacancy $vacancy): Collection;

    /**
     * Career page: active vacancies with branch, position and city, newest opened first.
     *
     * @return Collection<int, Vacancy>
     */
    public function published(): Collection;

    public function findPublishedBySlug(string $slug): ?Vacancy;

    /** @param  array<string, mixed>  $attributes */
    public function createCareerSubmission(array $attributes): CareerSubmission;

    /**
     * Vacancy form templates by name, then id.
     *
     * @return Collection<int, VacancyTemplate>
     */
    public function templates(int $limit): Collection;

    /** @param  array<string, mixed>  $data */
    public function createTemplate(string $name, array $data, int $createdBy): VacancyTemplate;

    public function saveTemplate(VacancyTemplate $template): void;

    public function deleteTemplate(VacancyTemplate $template): void;
}
