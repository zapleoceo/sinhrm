<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Modules\Recruiting\Contracts\ScreeningRepository;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\CandidateScreening;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class EloquentScreeningRepository implements ScreeningRepository
{
    public function create(array $attributes): CandidateScreening
    {
        return CandidateScreening::query()->create($attributes);
    }

    public function find(int $id): ?CandidateScreening
    {
        return CandidateScreening::query()->find($id);
    }

    public function update(CandidateScreening $screening, array $attributes): void
    {
        $screening->fill($attributes)->save();
    }

    public function finish(int $id, string $status, array $attributes): bool
    {
        $screening = CandidateScreening::query()->whereKey($id)->where('status', CandidateScreening::PENDING)->first();
        if ($screening === null) {
            return false;
        }
        // Conditional update keeps two finishers (request + cron) from both writing.
        $affected = CandidateScreening::query()->whereKey($id)->where('status', CandidateScreening::PENDING)
            ->update(['status' => $status, 'completed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
        if ($affected !== 1) {
            return false;
        }
        $screening->refresh()->fill($attributes)->save();

        return true;
    }

    public function latestForCandidate(int $candidateId): Collection
    {
        return CandidateScreening::query()
            ->with('vacancy:id,title')
            ->where('candidate_id', $candidateId)
            ->whereIn('id', CandidateScreening::query()->selectRaw('MAX(id)')->where('candidate_id', $candidateId)->groupBy('application_id'))
            ->orderByDesc('id')
            ->get();
    }

    public function pendingFor(int $applicationId): ?CandidateScreening
    {
        return CandidateScreening::query()->where('application_id', $applicationId)->where('status', CandidateScreening::PENDING)->first();
    }

    public function unscreenedApplications(Carbon $since, int $limit): Collection
    {
        return Application::query()
            ->where('status', ApplicationStatus::Active->value)
            ->where('created_at', '>=', $since)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('candidate_screenings')->whereColumn('candidate_screenings.application_id', 'applications.id'))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function materials(int $candidateId, int $limit): array
    {
        return Touchpoint::query()
            ->where('candidate_id', $candidateId)
            ->whereNotNull('body')
            ->where(fn (Builder $q) => $q
                ->where('channel', Channel::Note->value)
                ->orWhere(fn (Builder $q) => $q->where('direction', Direction::In->value)->where('channel', '!=', Channel::System->value)))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['channel', 'body'])
            ->map(static fn (Touchpoint $t): array => ['channel' => $t->channel->value, 'body' => (string) $t->body])
            ->values()
            ->all();
    }
}
