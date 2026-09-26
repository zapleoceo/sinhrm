<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\DTO\VacancyData;
use App\Modules\Recruiting\DTO\VacancyFilter;
use App\Modules\Recruiting\Enums\VacancyStatus;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/** Vacancies: listing in scope, create/edit (branch must be in the actor's scope), the kanban board. */
final readonly class VacancyService
{
    public function __construct(
        private VacancyRepository $vacancies,
        private PipelineRepository $pipelines,
        private RecruitingScope $scope,
        private LoggerInterface $log,
    ) {}

    /** @return LengthAwarePaginator<int, Vacancy> */
    public function list(User $actor, VacancyFilter $filter): LengthAwarePaginator
    {
        return $this->vacancies->paginate($this->scope->for($actor), $filter);
    }

    /** @throws ModelNotFoundException<Vacancy> */
    public function find(int $id): Vacancy
    {
        return $this->vacancies->find($id) ?? throw (new ModelNotFoundException)->setModel(Vacancy::class, [$id]);
    }

    /** @throws RecruitingException */
    public function create(User $actor, VacancyData $data): Vacancy
    {
        $attributes = $data->attributes;
        $this->assertBranch($this->scope->for($actor), (int) ($attributes['branch_id'] ?? 0));
        $attributes['pipeline_id'] ??= $this->pipelines->defaultPipeline()->id ?? throw RecruitingException::noDefaultPipeline();
        $attributes['recruiter_id'] ??= $actor->id;
        $attributes['status'] ??= VacancyStatus::Open->value;
        $attributes += $this->statusDates((string) $attributes['status'], null);
        $vacancy = $this->vacancies->create($attributes);
        $this->log->info('recruiting.vacancy_created', ['id' => $vacancy->id, 'by' => $actor->id]);

        return $this->find($vacancy->id);
    }

    /**
     * Opens a vacancy on behalf of the system (an approved hiring request): no actor scope check, the default
     * pipeline, status open. The caller decides who may trigger it.
     *
     * @throws RecruitingException no_default_pipeline
     */
    public function openOnBehalf(VacancyData $data, int $recruiterId): Vacancy
    {
        $attributes = $data->attributes;
        $attributes['pipeline_id'] = $this->pipelines->defaultPipeline()->id ?? throw RecruitingException::noDefaultPipeline();
        $attributes['recruiter_id'] = $recruiterId;
        $attributes['status'] = VacancyStatus::Open->value;
        $attributes += $this->statusDates(VacancyStatus::Open->value, null);
        $vacancy = $this->vacancies->create($attributes);
        $this->log->info('recruiting.vacancy_created', ['id' => $vacancy->id, 'by' => null, 'via' => 'hiring_request']);

        return $this->find($vacancy->id);
    }

    /** @throws RecruitingException */
    public function update(User $actor, Vacancy $vacancy, VacancyData $data): Vacancy
    {
        $attributes = $data->attributes;
        if ($attributes === []) {
            return $vacancy;
        }
        if (isset($attributes['branch_id'])) {
            $this->assertBranch($this->scope->for($actor), (int) $attributes['branch_id']);
        }
        if (isset($attributes['status']) && is_string($attributes['status'])) {
            $attributes += $this->statusDates($attributes['status'], $vacancy);
        }
        $this->vacancies->update($vacancy, $attributes);
        $this->log->info('recruiting.vacancy_updated', ['id' => $vacancy->id, 'by' => $actor->id, 'fields' => array_keys($attributes)]);

        return $this->find($vacancy->id);
    }

    /** @return Collection<int, Application> */
    public function boardApplications(Vacancy $vacancy): Collection
    {
        return $this->vacancies->boardApplications($vacancy);
    }

    private function assertBranch(Scope $scope, int $branchId): void
    {
        if (! $scope->allowsBranch($branchId)) {
            throw RecruitingException::vacancyOutOfScope();
        }
    }

    /** @return array<string, Carbon|null> */
    private function statusDates(string $status, ?Vacancy $vacancy): array
    {
        $now = Carbon::now();

        return match ($status) {
            VacancyStatus::Open->value => ['opened_at' => $vacancy->opened_at ?? $now, 'closed_at' => null],
            VacancyStatus::Closed->value => ['closed_at' => $vacancy->closed_at ?? $now],
            default => [],
        };
    }
}
