<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\DTO\CandidateFilter;
use App\Modules\Recruiting\DTO\ContactKeys;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Models\Candidate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CandidateRepository
{
    /** @return LengthAwarePaginator<int, Candidate> with applications (vacancy, stage) */
    public function paginate(Scope $scope, CandidateFilter $filter): LengthAwarePaginator;

    public function find(int $id): ?Candidate;

    /**
     * First candidate sharing any normalized contact, checked in order phone → email → telegram.
     *
     * @return array{0: Candidate, 1: 'phone'|'email'|'telegram'}|null
     */
    public function findByContacts(ContactKeys $keys, ?int $exceptId = null): ?array;

    /** The candidate a normalized profile URL (browser extension import) belongs to. */
    public function findByProfileUrl(string $url): ?Candidate;

    /** Links a normalized profile URL to the candidate; false when the URL is already linked (to anyone). */
    public function attachProfileUrl(int $candidateId, string $site, string $url): bool;

    public function isVisible(Scope $scope, int $candidateId): bool;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Candidate;

    /** @param  array<string, mixed>  $attributes */
    public function update(Candidate $candidate, array $attributes): Candidate;
}
