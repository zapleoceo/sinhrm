<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\DTO\CandidateFilter;
use App\Modules\Recruiting\DTO\ContactKeys;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Models\Candidate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class EloquentCandidateRepository implements CandidateRepository
{
    public function paginate(Scope $scope, CandidateFilter $filter): LengthAwarePaginator
    {
        $appFilter = $filter->vacancyId !== null || $filter->stageId !== null || $filter->status !== null;

        return $this->scoped(Candidate::query(), $scope)
            ->with(['applications' => fn ($q) => $q->with(['vacancy', 'stage'])->orderByDesc('updated_at')])
            ->when($filter->q, function (Builder $q, string $term): void {
                $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
                $telegram = '%'.addcslashes(ltrim(mb_strtolower($term), '@'), '%_\\').'%';
                $digits = (string) preg_replace('/\D+/', '', $term);
                $q->where(function (Builder $w) use ($like, $telegram, $digits): void {
                    $w->whereRaw('lower(full_name) like ?', [$like])
                        ->orWhere('email', 'like', $like)
                        ->orWhere('telegram_username', 'like', $telegram);
                    if (strlen($digits) >= 3) {
                        $w->orWhere('phone', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->when($appFilter, fn (Builder $q) => $q->whereHas('applications', function (Builder $a) use ($filter): void {
                $a->when($filter->vacancyId, fn (Builder $x, int $id) => $x->where('vacancy_id', $id))
                    ->when($filter->stageId, fn (Builder $x, int $id) => $x->where('stage_id', $id))
                    ->when($filter->status, fn (Builder $x, ApplicationStatus $s) => $x->where('status', $s->value));
            }))
            ->when($filter->source, fn (Builder $q, CandidateSource $s) => $q->where('source', $s->value))
            ->when($filter->ownerId, fn (Builder $q, int $id) => $q->where('owner_id', $id))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($filter->perPage);
    }

    public function find(int $id): ?Candidate
    {
        return Candidate::query()->with(['city', 'owner'])->find($id);
    }

    public function findByContacts(ContactKeys $keys, ?int $exceptId = null): ?array
    {
        foreach (['phone' => $keys->phone, 'email' => $keys->email, 'telegram' => $keys->telegram] as $field => $value) {
            if ($value === null) {
                continue;
            }
            $column = $field === 'telegram' ? 'telegram_username' : $field;
            $found = Candidate::query()
                ->where($column, $value)
                ->when($exceptId, fn (Builder $q, int $id) => $q->where('id', '!=', $id))
                ->orderBy('id')
                ->first();
            if ($found !== null) {
                return [$found, $field];
            }
        }

        return null;
    }

    public function findByProfileUrl(string $url): ?Candidate
    {
        return Candidate::query()
            ->whereIn('id', DB::table('candidate_profile_urls')->select('candidate_id')->where('url', $url))
            ->first();
    }

    public function attachProfileUrl(int $candidateId, string $site, string $url): bool
    {
        $now = now();

        return DB::table('candidate_profile_urls')->insertOrIgnore([
            'candidate_id' => $candidateId, 'site' => $site, 'url' => $url, 'created_at' => $now, 'updated_at' => $now,
        ]) === 1;
    }

    public function isVisible(Scope $scope, int $candidateId): bool
    {
        return $this->scoped(Candidate::query(), $scope)->whereKey($candidateId)->exists();
    }

    public function create(array $attributes): Candidate
    {
        return Candidate::query()->create($attributes);
    }

    public function update(Candidate $candidate, array $attributes): Candidate
    {
        $candidate->fill($attributes)->save();

        return $candidate;
    }

    /**
     * Restricted users see candidates they own/created and candidates with an application on a vacancy of their
     * branches.
     *
     * @param  Builder<Candidate>  $query
     * @return Builder<Candidate>
     */
    private function scoped(Builder $query, Scope $scope): Builder
    {
        if ($scope->isUnrestricted()) {
            return $query;
        }

        return $query->where(function (Builder $w) use ($scope): void {
            $w->where('owner_id', $scope->userId)
                ->orWhere('created_by', $scope->userId)
                ->orWhereExists(function (QueryBuilder $sub) use ($scope): void {
                    $sub->selectRaw('1')
                        ->from('applications')
                        ->join('vacancies', 'vacancies.id', '=', 'applications.vacancy_id')
                        ->whereColumn('applications.candidate_id', 'candidates.id')
                        ->whereIn('vacancies.branch_id', $scope->branchIds ?? []);
                });
        });
    }
}
