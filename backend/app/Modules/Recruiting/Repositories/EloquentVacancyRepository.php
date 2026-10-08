<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Modules\Core\Support\Database\Like;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\DTO\VacancyFilter;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\VacancyStatus;
use App\Modules\Recruiting\Models\CareerSubmission;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Models\VacancyTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentVacancyRepository implements VacancyRepository
{
    /** What the public career page shows. */
    private const array PUBLIC_RELATIONS = ['branch', 'position', 'city'];

    private const array RELATIONS = ['branch', 'department', 'position', 'recruiter', 'hiringManager', 'pipeline.stages', 'category', 'city'];

    public function paginate(Scope $scope, VacancyFilter $filter): LengthAwarePaginator
    {
        return $this->withCounts($this->scoped($scope)->with(self::RELATIONS))
            ->when($filter->active, fn (Builder $q) => $q->active())
            ->when($filter->q, function (Builder $q, string $term): void {
                $q->whereRaw('lower(title) like ?', [Like::contains(mb_strtolower($term))]);
            })
            ->when($filter->status, fn (Builder $q, VacancyStatus $s) => $q->where('status', $s->value))
            ->when($filter->branchId, fn (Builder $q, int $id) => $q->where('branch_id', $id))
            ->when($filter->recruiterId, fn (Builder $q, int $id) => $q->where('recruiter_id', $id))
            ->orderByRaw("case status when 'open' then 0 when 'paused' then 1 else 2 end")
            ->orderByDesc('id')
            ->paginate($filter->perPage);
    }

    public function activeCount(Scope $scope): int
    {
        return $this->scoped($scope)->active()->count();
    }

    public function find(int $id): ?Vacancy
    {
        return $this->withCounts(Vacancy::query()->with(self::RELATIONS))->find($id);
    }

    public function findOpenByTitle(string $title): ?Vacancy
    {
        $title = mb_strtolower(trim($title));
        if ($title === '') {
            return null;
        }
        // Compared in PHP: SQL lower() is ASCII-only on some engines (Cyrillic titles). Open vacancies are few.
        $matches = Vacancy::query()
            ->where('status', VacancyStatus::Open->value)
            ->get()
            ->filter(static fn (Vacancy $v): bool => mb_strtolower(trim($v->title)) === $title);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function create(array $attributes): Vacancy
    {
        return Vacancy::query()->create($attributes);
    }

    public function update(Vacancy $vacancy, array $attributes): Vacancy
    {
        $vacancy->fill($attributes)->save();

        return $vacancy;
    }

    public function boardApplications(Vacancy $vacancy): Collection
    {
        return $vacancy->applications()
            ->addSelect(['applications.*', 'screening_score' => ScreeningRanking::applicationScore()])
            ->with(['candidate', 'rejectReason'])
            ->orderByDesc('stage_entered_at')
            ->orderByDesc('id')
            ->get();
    }

    public function published(): Collection
    {
        return Vacancy::query()->with(self::PUBLIC_RELATIONS)->active()
            ->orderByDesc('opened_at')->orderByDesc('id')->get();
    }

    public function findPublishedBySlug(string $slug): ?Vacancy
    {
        return Vacancy::query()->with(self::PUBLIC_RELATIONS)->active()->where('slug', $slug)->first();
    }

    public function createCareerSubmission(array $attributes): CareerSubmission
    {
        return CareerSubmission::query()->create($attributes);
    }

    public function templates(int $limit): Collection
    {
        return VacancyTemplate::query()->orderBy('name')->orderBy('id')->limit($limit)->get();
    }

    public function createTemplate(string $name, array $data, int $createdBy): VacancyTemplate
    {
        return VacancyTemplate::query()->create(['name' => $name, 'data' => $data, 'created_by' => $createdBy]);
    }

    public function saveTemplate(VacancyTemplate $template): void
    {
        $template->save();
    }

    public function deleteTemplate(VacancyTemplate $template): void
    {
        $template->delete();
    }

    /** @return Builder<Vacancy> */
    private function scoped(Scope $scope): Builder
    {
        return Vacancy::query()->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->where(
            fn (Builder $w) => $w->whereIn('branch_id', $scope->branchIds ?? [])->orWhereIn('id', $scope->managedVacancyIds),
        ));
    }

    /**
     * @param  Builder<Vacancy>  $query
     * @return Builder<Vacancy>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'applications',
            'applications as active_applications_count' => fn (Builder $q) => $q->where('status', ApplicationStatus::Active->value),
        ]);
    }
}
