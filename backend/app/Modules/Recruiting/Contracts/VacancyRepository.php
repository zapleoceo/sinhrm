<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\DTO\VacancyFilter;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface VacancyRepository
{
    /** @return LengthAwarePaginator<int, Vacancy> */
    public function paginate(Scope $scope, VacancyFilter $filter): LengthAwarePaginator;

    /** With branch, recruiter, pipeline stages and application counts. */
    public function find(int $id): ?Vacancy;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Vacancy;

    /** @param  array<string, mixed>  $attributes */
    public function update(Vacancy $vacancy, array $attributes): Vacancy;

    /** The single open vacancy whose title equals $title case-insensitively; none or several → null. */
    public function findOpenByTitle(string $title): ?Vacancy;

    /** @return Collection<int, Application> every application of the vacancy with its candidate */
    public function boardApplications(Vacancy $vacancy): Collection;
}
