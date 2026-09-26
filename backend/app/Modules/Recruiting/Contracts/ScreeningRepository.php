<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\CandidateScreening;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

interface ScreeningRepository
{
    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): CandidateScreening;

    public function find(int $id): ?CandidateScreening;

    /** @param  array<string, mixed>  $attributes */
    public function update(CandidateScreening $screening, array $attributes): void;

    /**
     * pending → done|failed only (a finished screening is never overwritten); false when it was not pending.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function finish(int $id, string $status, array $attributes): bool;

    /** @return Collection<int, CandidateScreening> the latest screening of each application of the candidate, newest first */
    public function latestForCandidate(int $candidateId): Collection;

    public function pendingFor(int $applicationId): ?CandidateScreening;

    /** @return Collection<int, Application> active applications created since $since without any screening, oldest first */
    public function unscreenedApplications(Carbon $since, int $limit): Collection;

    /**
     * What the candidate sent or what was written about them (notes, e-mails/messages/transcripts from the candidate),
     * newest first. Bodies only — no authors, dates or contacts.
     *
     * @return list<array{channel: string, body: string}>
     */
    public function materials(int $candidateId, int $limit): array;
}
